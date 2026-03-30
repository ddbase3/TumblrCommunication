<?php declare(strict_types=1);

namespace TumblrCommunication\Service;

use Base3\Configuration\Api\IConfiguration;
use CommunicationFoundation\Api\ICommunicationPost;
use CommunicationFoundation\Api\ICommunicationPostMedia;
use CommunicationFoundation\Api\ICommunicationPublishRequest;
use CommunicationFoundation\Api\ICommunicationPublishResult;
use CommunicationFoundation\Api\ICommunicationPublisher;
use CommunicationFoundation\Exception\CommunicationPublishException;
use CommunicationFoundation\Model\CommunicationPublishResult;
use Throwable;
use Tumblr\API\Client as TumblrClient;

/**
 * Publishes generic communication posts to Tumblr.
 *
 * This service adapts the provider-neutral CommunicationFoundation contracts
 * to Tumblr's legacy post creation flow via the official PHP client. It is
 * intentionally focused on publishing only and does not contain any logic for
 * caption generation, image analysis or other AI-assisted preparation steps.
 *
 * Configuration is resolved from the "tumblr" configuration group via the
 * injected IConfiguration instance. The following keys are required:
 *
 * - tumblrConsumerKey
 * - tumblrConsumerSecret
 * - tumblrToken
 * - tumblrTokenSecret
 * - blogIdentifier
 *
 * The publish target can be overridden per request through
 * ICommunicationPublishRequest::getTarget(). If no explicit target is provided,
 * the configured blogIdentifier is used.
 *
 * Supported mappings in this first implementation:
 *
 * - text posts without media
 * - link posts without media
 * - photo posts with one or more images
 * - video posts with exactly one video
 *
 * Explicit non-goals of this first implementation:
 *
 * - mixed image/video posts in one request
 * - more than one video in one request
 * - automatic mapping of publishAt to Tumblr scheduling
 *
 * Provider-specific Tumblr options can still be passed through
 * ICommunicationPublishRequest::getProviderOptions(). These options are merged
 * into the request payload only if they do not overwrite the core fields that
 * are derived from the generic post model.
 */
class TumblrPublisher implements ICommunicationPublisher
{
	private const CONFIG_GROUP = 'tumblr';

	private IConfiguration $configuration;

	private ?TumblrClient $client = null;

	/**
	 * Creates a new Tumblr publisher service.
	 *
	 * @param IConfiguration $configuration
	 * Central BASE3 configuration service used to resolve Tumblr credentials
	 * and the default target blog identifier.
	 */
	public function __construct(IConfiguration $configuration)
	{
		$this->configuration = $configuration;
	}

	/**
	 * Publishes the given communication request to Tumblr.
	 *
	 * The method validates the request, resolves the effective Tumblr target,
	 * builds the provider-specific request payload and executes the post creation
	 * through the Tumblr API client.
	 *
	 * On successful publication a normalized CommunicationPublishResult is
	 * returned. Provider failures and structural incompatibilities are reported
	 * as CommunicationPublishException instances.
	 *
	 * @param ICommunicationPublishRequest $request
	 * The generic publish request that should be sent to Tumblr.
	 *
	 * @return ICommunicationPublishResult
	 * Returns the normalized result of the Tumblr publish operation.
	 *
	 * @throws CommunicationPublishException
	 * Thrown if configuration is missing, the request cannot be mapped safely
	 * to Tumblr or the Tumblr API call fails.
	 */
	public function publish(ICommunicationPublishRequest $request): ICommunicationPublishResult
	{
		$this->assertSchedulingIsSupported($request);

		$blogIdentifier = $this->resolveBlogIdentifier($request);
		$params = $this->buildCreatePostParameters($request);

		try {
			$response = $this->getClient()->createPost($blogIdentifier, $params);
		} catch (Throwable $throwable) {
			throw new CommunicationPublishException(
				'Tumblr publish failed: ' . $throwable->getMessage(),
				[
					'provider' => 'tumblr',
					'blogIdentifier' => $blogIdentifier,
					'postType' => (string)($params['type'] ?? ''),
				],
				0,
				$throwable
			);
		}

		$rawResult = $this->normalizeRawResult($response);
		$externalId = $this->extractStringValue($rawResult, 'id');
		$externalUrl = $this->extractStringValue($rawResult, 'post_url');

		return new CommunicationPublishResult(
			true,
			$externalId,
			$externalUrl,
			null,
			null,
			$rawResult
		);
	}

	/**
	 * Returns the lazily initialized Tumblr API client.
	 *
	 * The client is created only once and then reused for subsequent publish
	 * calls of the same service instance.
	 *
	 * @return TumblrClient
	 * Returns the initialized Tumblr API client.
	 *
	 * @throws CommunicationPublishException
	 * Thrown if required Tumblr credentials are missing in the configuration.
	 */
	private function getClient(): TumblrClient
	{
		if ($this->client !== null) {
			return $this->client;
		}

		$this->client = new TumblrClient(
			$this->getRequiredConfigValue('tumblrConsumerKey'),
			$this->getRequiredConfigValue('tumblrConsumerSecret'),
			$this->getRequiredConfigValue('tumblrToken'),
			$this->getRequiredConfigValue('tumblrTokenSecret')
		);

		return $this->client;
	}

	/**
	 * Resolves the effective Tumblr blog identifier for the current request.
	 *
	 * If the request contains an explicit target, that value wins. Otherwise the
	 * default blogIdentifier from the Tumblr configuration group is used.
	 *
	 * @param ICommunicationPublishRequest $request
	 * Publish request whose target should be resolved.
	 *
	 * @return string
	 * Returns the effective Tumblr blog identifier.
	 *
	 * @throws CommunicationPublishException
	 * Thrown if neither the request target nor the configured default target is
	 * available.
	 */
	private function resolveBlogIdentifier(ICommunicationPublishRequest $request): string
	{
		$target = trim((string)$request->getTarget());

		if ($target !== '') {
			return $target;
		}

		return $this->getRequiredConfigValue('blogIdentifier');
	}

	/**
	 * Builds the Tumblr create-post parameter payload for the given request.
	 *
	 * The method chooses the most suitable Tumblr post type based on the generic
	 * post content:
	 *
	 * - media present -> photo or video post
	 * - no media but link present -> link post
	 * - otherwise -> text post
	 *
	 * @param ICommunicationPublishRequest $request
	 * Generic publish request to map.
	 *
	 * @return array<string, mixed>
	 * Returns the Tumblr create-post parameter map.
	 *
	 * @throws CommunicationPublishException
	 * Thrown if the request cannot be represented safely as a Tumblr post.
	 */
	private function buildCreatePostParameters(ICommunicationPublishRequest $request): array
	{
		$post = $request->getPost();

		$this->assertPostHasContent($post);

		if ($post->getMedia() !== []) {
			return $this->buildMediaPostParameters($request);
		}

		if ($this->hasNonEmptyValue($post->getLink())) {
			return $this->buildLinkPostParameters($request);
		}

		return $this->buildTextPostParameters($request);
	}

	/**
	 * Builds a Tumblr text post payload.
	 *
	 * The generic post title is mapped to Tumblr's title field. The caption and
	 * optional link are merged into the text body.
	 *
	 * @param ICommunicationPublishRequest $request
	 * Request to map.
	 *
	 * @return array<string, mixed>
	 * Returns the Tumblr parameter map for a text post.
	 */
	private function buildTextPostParameters(ICommunicationPublishRequest $request): array
	{
		$post = $request->getPost();

		$params = [
			'type' => 'text',
			'body' => $this->buildTextBody($post),
		];

		if ($this->hasNonEmptyValue($post->getTitle())) {
			$params['title'] = $post->getTitle();
		}

		return $this->applyCommonParameters($params, $request);
	}

	/**
	 * Builds a Tumblr link post payload.
	 *
	 * The generic link becomes the Tumblr link URL. The caption is mapped to the
	 * Tumblr description field. If a title is present it is forwarded as well.
	 *
	 * @param ICommunicationPublishRequest $request
	 * Request to map.
	 *
	 * @return array<string, mixed>
	 * Returns the Tumblr parameter map for a link post.
	 *
	 * @throws CommunicationPublishException
	 * Thrown if no link URL is available.
	 */
	private function buildLinkPostParameters(ICommunicationPublishRequest $request): array
	{
		$post = $request->getPost();
		$link = trim((string)$post->getLink());

		if ($link === '') {
			throw new CommunicationPublishException(
				'Cannot build Tumblr link post without a link URL.',
				['provider' => 'tumblr']
			);
		}

		$params = [
			'type' => 'link',
			'url' => $link,
		];

		if ($this->hasNonEmptyValue($post->getTitle())) {
			$params['title'] = $post->getTitle();
		}

		$description = $this->buildLinkDescription($post);
		if ($description !== '') {
			$params['description'] = $description;
		}

		return $this->applyCommonParameters($params, $request);
	}

	/**
	 * Builds a Tumblr media post payload.
	 *
	 * This method supports two distinct mappings:
	 *
	 * - one or more images -> Tumblr photo post
	 * - exactly one video -> Tumblr video post
	 *
	 * Mixed media posts and multiple videos are rejected explicitly because this
	 * first implementation is intended to stay simple and predictable.
	 *
	 * @param ICommunicationPublishRequest $request
	 * Request to map.
	 *
	 * @return array<string, mixed>
	 * Returns the Tumblr parameter map for the media post.
	 *
	 * @throws CommunicationPublishException
	 * Thrown if the media combination or media source format is unsupported.
	 */
	private function buildMediaPostParameters(ICommunicationPublishRequest $request): array
	{
		$post = $request->getPost();
		$media = $post->getMedia();

		$imageMedia = [];
		$videoMedia = [];

		foreach ($media as $item) {
			$kind = $this->resolveMediaKind($item);

			if ($kind === 'image') {
				$imageMedia[] = $item;
				continue;
			}

			if ($kind === 'video') {
				$videoMedia[] = $item;
				continue;
			}

			throw new CommunicationPublishException(
				'Unsupported Tumblr media type.',
				[
					'provider' => 'tumblr',
					'mediaType' => $item->getType(),
					'mimeType' => (string)$item->getMimeType(),
				]
			);
		}

		if ($imageMedia !== [] && $videoMedia !== []) {
			throw new CommunicationPublishException(
				'Tumblr publisher does not support mixed image and video posts in one request.',
				['provider' => 'tumblr']
			);
		}

		if (count($videoMedia) > 1) {
			throw new CommunicationPublishException(
				'Tumblr publisher currently supports only one video per post.',
				['provider' => 'tumblr']
			);
		}

		if ($imageMedia !== []) {
			return $this->buildPhotoPostParameters($request, $imageMedia);
		}

		if ($videoMedia !== []) {
			return $this->buildVideoPostParameters($request, $videoMedia[0]);
		}

		throw new CommunicationPublishException(
			'Cannot build Tumblr media post without supported media.',
			['provider' => 'tumblr']
		);
	}

	/**
	 * Builds a Tumblr photo post payload.
	 *
	 * Supported image source variants:
	 *
	 * - one local file path
	 * - multiple local file paths
	 * - one remote URL
	 *
	 * Multiple remote URLs are rejected explicitly because this service is meant
	 * to stay aligned with the proven local-file upload flow from the existing
	 * test implementation.
	 *
	 * @param ICommunicationPublishRequest $request
	 * Request to map.
	 *
	 * @param array<int, ICommunicationPostMedia> $imageMedia
	 * Ordered list of image media items.
	 *
	 * @return array<string, mixed>
	 * Returns the Tumblr parameter map for a photo post.
	 *
	 * @throws CommunicationPublishException
	 * Thrown if an image source is unsupported or invalid.
	 */
	private function buildPhotoPostParameters(ICommunicationPublishRequest $request, array $imageMedia): array
	{
		$post = $request->getPost();
		$params = [
			'type' => 'photo',
		];

		$caption = $this->buildMediaCaption($post);
		if ($caption !== '') {
			$params['caption'] = $caption;
		}

		if ($this->hasNonEmptyValue($post->getLink())) {
			$params['link'] = $post->getLink();
		}

		if (count($imageMedia) === 1) {
			$source = $imageMedia[0]->getSource();

			if ($this->isUrl($source)) {
				$params['source'] = $source;
			} else {
				$params['data'] = $this->requireLocalFile($source);
			}

			return $this->applyCommonParameters($params, $request);
		}

		$localPaths = [];

		foreach ($imageMedia as $item) {
			$source = $item->getSource();

			if ($this->isUrl($source)) {
				throw new CommunicationPublishException(
					'Tumblr photo sets currently require local file paths for all images.',
					[
						'provider' => 'tumblr',
						'source' => $source,
					]
				);
			}

			$localPaths[] = $this->requireLocalFile($source);
		}

		$params['data'] = $localPaths;

		return $this->applyCommonParameters($params, $request);
	}

	/**
	 * Builds a Tumblr video post payload.
	 *
	 * Supported source variants:
	 *
	 * - local file path -> mapped to data
	 * - remote URL -> mapped to embed
	 *
	 * @param ICommunicationPublishRequest $request
	 * Request to map.
	 *
	 * @param ICommunicationPostMedia $videoMedia
	 * The single video media item.
	 *
	 * @return array<string, mixed>
	 * Returns the Tumblr parameter map for a video post.
	 *
	 * @throws CommunicationPublishException
	 * Thrown if the video source is unsupported or invalid.
	 */
	private function buildVideoPostParameters(ICommunicationPublishRequest $request, ICommunicationPostMedia $videoMedia): array
	{
		$post = $request->getPost();
		$source = $videoMedia->getSource();

		$params = [
			'type' => 'video',
		];

		$caption = $this->buildMediaCaption($post);
		if ($caption !== '') {
			$params['caption'] = $caption;
		}

		if ($this->isUrl($source)) {
			$params['embed'] = $source;
		} else {
			$params['data'] = $this->requireLocalFile($source);
		}

		return $this->applyCommonParameters($params, $request);
	}

	/**
	 * Applies shared Tumblr parameters that are independent from the concrete
	 * Tumblr post type.
	 *
	 * This currently includes:
	 *
	 * - tags
	 * - state derived from visibility or providerOptions
	 * - non-conflicting provider-specific option keys
	 *
	 * Provider-specific options never overwrite the core fields already derived
	 * by this service. This keeps the generic mapping deterministic.
	 *
	 * @param array<string, mixed> $params
	 * Base Tumblr parameter map.
	 *
	 * @param ICommunicationPublishRequest $request
	 * Request whose generic metadata and provider options should be applied.
	 *
	 * @return array<string, mixed>
	 * Returns the final Tumblr parameter map.
	 */
	private function applyCommonParameters(array $params, ICommunicationPublishRequest $request): array
	{
		$tags = $this->normalizeTags($request->getPost()->getTags());
		if ($tags !== []) {
			$params['tags'] = implode(',', $tags);
		}

		$state = $this->resolveState($request);
		if ($state !== null) {
			$params['state'] = $state;
		}

		foreach ($request->getProviderOptions() as $key => $value) {
			if (!array_key_exists($key, $params) && $value !== null) {
				$params[$key] = $value;
			}
		}

		return $params;
	}

	/**
	 * Resolves the Tumblr state for the current request.
	 *
	 * Resolution order:
	 *
	 * 1. providerOptions["state"]
	 * 2. generic visibility mapping
	 * 3. null, letting Tumblr use its default behavior
	 *
	 * Supported generic visibility mappings:
	 *
	 * - public -> published
	 * - published -> published
	 * - private -> private
	 * - draft -> draft
	 * - queue -> queue
	 *
	 * @param ICommunicationPublishRequest $request
	 * Request whose visibility or provider options should be mapped.
	 *
	 * @return string|null
	 * Returns the Tumblr state or null if no explicit state should be sent.
	 */
	private function resolveState(ICommunicationPublishRequest $request): ?string
	{
		$options = $request->getProviderOptions();

		if (isset($options['state']) && is_string($options['state'])) {
			$state = trim($options['state']);
			if ($state !== '') {
				return $state;
			}
		}

		$visibility = strtolower(trim((string)$request->getVisibility()));

		if ($visibility === '') {
			return null;
		}

		return match ($visibility) {
			'public', 'published' => 'published',
			'private' => 'private',
			'draft' => 'draft',
			'queue' => 'queue',
			default => null,
		};
	}

	/**
	 * Builds the body text for a Tumblr text post.
	 *
	 * The result combines caption and link in a simple plain-text-friendly form.
	 *
	 * @param ICommunicationPost $post
	 * Generic post to render.
	 *
	 * @return string
	 * Returns the text body for Tumblr.
	 */
	private function buildTextBody(ICommunicationPost $post): string
	{
		return $this->joinNonEmptyParts([
			$post->getCaption(),
			$post->getLink(),
		]);
	}

	/**
	 * Builds the description text for a Tumblr link post.
	 *
	 * The result currently contains only the generic caption because the link
	 * itself is sent via Tumblr's dedicated URL field.
	 *
	 * @param ICommunicationPost $post
	 * Generic post to render.
	 *
	 * @return string
	 * Returns the link description.
	 */
	private function buildLinkDescription(ICommunicationPost $post): string
	{
		return $this->joinNonEmptyParts([
			$post->getCaption(),
		]);
	}

	/**
	 * Builds the caption text for Tumblr media posts.
	 *
	 * For photo and video posts, Tumblr uses a caption field instead of separate
	 * title/body fields. This method therefore combines title, caption and the
	 * optional link into one coherent text block.
	 *
	 * @param ICommunicationPost $post
	 * Generic post to render.
	 *
	 * @return string
	 * Returns the media caption text.
	 */
	private function buildMediaCaption(ICommunicationPost $post): string
	{
		return $this->joinNonEmptyParts([
			$post->getTitle(),
			$post->getCaption(),
			$post->getLink(),
		]);
	}

	/**
	 * Joins the given text fragments with blank lines while removing empty
	 * values.
	 *
	 * @param array<int, string|null> $parts
	 * Candidate text fragments.
	 *
	 * @return string
	 * Returns the joined text block.
	 */
	private function joinNonEmptyParts(array $parts): string
	{
		$result = [];

		foreach ($parts as $part) {
			$value = trim((string)$part);

			if ($value === '') {
				continue;
			}

			$result[] = $value;
		}

		return implode("\n\n", $result);
	}

	/**
	 * Normalizes and de-duplicates the generic tag list for Tumblr.
	 *
	 * Leading hashtag markers are removed because Tumblr accepts logical tag
	 * values and this service should not depend on the caller already formatting
	 * tags in a provider-specific way.
	 *
	 * @param array<int, string> $tags
	 * Generic tag list.
	 *
	 * @return array<int, string>
	 * Returns the normalized ordered tag list.
	 */
	private function normalizeTags(array $tags): array
	{
		$result = [];
		$seen = [];

		foreach ($tags as $tag) {
			$value = trim($tag);
			$value = ltrim($value, '#');

			if ($value === '') {
				continue;
			}

			$key = mb_strtolower($value);

			if (isset($seen[$key])) {
				continue;
			}

			$seen[$key] = true;
			$result[] = $value;
		}

		return $result;
	}

	/**
	 * Resolves the generic media type to the Tumblr-relevant logical kind.
	 *
	 * The method first inspects the declared generic media type and then falls
	 * back to the MIME type if necessary.
	 *
	 * @param ICommunicationPostMedia $media
	 * Media item to inspect.
	 *
	 * @return string
	 * Returns "image" or "video".
	 *
	 * @throws CommunicationPublishException
	 * Thrown if the media item cannot be classified.
	 */
	private function resolveMediaKind(ICommunicationPostMedia $media): string
	{
		$type = strtolower(trim($media->getType()));
		$mimeType = strtolower(trim((string)$media->getMimeType()));

		if ($type === 'image' || str_starts_with($type, 'image/')) {
			return 'image';
		}

		if ($type === 'video' || str_starts_with($type, 'video/')) {
			return 'video';
		}

		if ($mimeType !== '') {
			if (str_starts_with($mimeType, 'image/')) {
				return 'image';
			}

			if (str_starts_with($mimeType, 'video/')) {
				return 'video';
			}
		}

		throw new CommunicationPublishException(
			'Unable to determine Tumblr media kind from media type or MIME type.',
			[
				'provider' => 'tumblr',
				'mediaType' => $media->getType(),
				'mimeType' => (string)$media->getMimeType(),
			]
		);
	}

	/**
	 * Ensures that a local filesystem path exists and is readable.
	 *
	 * @param string $path
	 * Local file path to validate.
	 *
	 * @return string
	 * Returns the validated local file path unchanged.
	 *
	 * @throws CommunicationPublishException
	 * Thrown if the path is empty, missing or not readable.
	 */
	private function requireLocalFile(string $path): string
	{
		$normalizedPath = trim($path);

		if ($normalizedPath === '') {
			throw new CommunicationPublishException(
				'Cannot publish Tumblr media without a local file path.',
				['provider' => 'tumblr']
			);
		}

		if (!is_file($normalizedPath)) {
			throw new CommunicationPublishException(
				'Tumblr media file does not exist.',
				[
					'provider' => 'tumblr',
					'path' => $normalizedPath,
				]
			);
		}

		if (!is_readable($normalizedPath)) {
			throw new CommunicationPublishException(
				'Tumblr media file is not readable.',
				[
					'provider' => 'tumblr',
					'path' => $normalizedPath,
				]
			);
		}

		return $normalizedPath;
	}

	/**
	 * Ensures that the publish request does not use scheduling.
	 *
	 * This first Tumblr publisher implementation uses the proven legacy client
	 * flow from the existing working test. Planned publishing is therefore
	 * rejected explicitly instead of being mapped in an uncertain or implicit
	 * way.
	 *
	 * @param ICommunicationPublishRequest $request
	 * Request to validate.
	 *
	 * @return void
	 *
	 * @throws CommunicationPublishException
	 * Thrown if publishAt is set.
	 */
	private function assertSchedulingIsSupported(ICommunicationPublishRequest $request): void
	{
		if ($request->getPublishAt() !== null) {
			throw new CommunicationPublishException(
				'This Tumblr publisher does not support publishAt yet. Scheduled publishing should be implemented via the newer Tumblr posts endpoint in a dedicated follow-up integration.',
				['provider' => 'tumblr']
			);
		}
	}

	/**
	 * Ensures that the generic post contains at least some publishable content.
	 *
	 * @param ICommunicationPost $post
	 * Post to validate.
	 *
	 * @return void
	 *
	 * @throws CommunicationPublishException
	 * Thrown if the post is completely empty.
	 */
	private function assertPostHasContent(ICommunicationPost $post): void
	{
		$hasTitle = $this->hasNonEmptyValue($post->getTitle());
		$hasCaption = $this->hasNonEmptyValue($post->getCaption());
		$hasLink = $this->hasNonEmptyValue($post->getLink());
		$hasMedia = $post->getMedia() !== [];

		if ($hasTitle || $hasCaption || $hasLink || $hasMedia) {
			return;
		}

		throw new CommunicationPublishException(
			'Cannot publish an empty Tumblr post.',
			['provider' => 'tumblr']
		);
	}

	/**
	 * Returns whether the given value contains non-empty text.
	 *
	 * @param string|null $value
	 * Value to inspect.
	 *
	 * @return bool
	 * Returns true if the value contains non-empty text, otherwise false.
	 */
	private function hasNonEmptyValue(?string $value): bool
	{
		return trim((string)$value) !== '';
	}

	/**
	 * Returns whether the given source string looks like a URL.
	 *
	 * @param string $value
	 * Value to inspect.
	 *
	 * @return bool
	 * Returns true if the value is a syntactically valid URL.
	 */
	private function isUrl(string $value): bool
	{
		return filter_var($value, FILTER_VALIDATE_URL) !== false;
	}

	/**
	 * Resolves one required configuration value from the Tumblr configuration
	 * group.
	 *
	 * @param string $key
	 * Configuration key inside the "tumblr" group.
	 *
	 * @return string
	 * Returns the non-empty configuration value.
	 *
	 * @throws CommunicationPublishException
	 * Thrown if the configuration value is missing or empty.
	 */
	private function getRequiredConfigValue(string $key): string
	{
		$value = trim($this->configuration->getString(self::CONFIG_GROUP, $key, ''));

		if ($value !== '') {
			return $value;
		}

		throw new CommunicationPublishException(
			sprintf('Missing required Tumblr configuration value "%s".', $key),
			[
				'provider' => 'tumblr',
				'configGroup' => self::CONFIG_GROUP,
				'configKey' => $key,
			]
		);
	}

	/**
	 * Normalizes the raw Tumblr client response to a string-keyed array suitable
	 * for CommunicationPublishResult.
	 *
	 * @param mixed $response
	 * Raw response returned by the Tumblr client.
	 *
	 * @return array<string, mixed>
	 * Returns the normalized result payload.
	 */
	private function normalizeRawResult(mixed $response): array
	{
		$normalized = json_decode(json_encode($response), true);

		if (is_array($normalized) && $this->hasOnlyStringKeys($normalized)) {
			return $normalized;
		}

		return [
			'response' => $normalized,
		];
	}

	/**
	 * Returns whether all top-level array keys are strings.
	 *
	 * @param array<mixed, mixed> $data
	 * Array to inspect.
	 *
	 * @return bool
	 * Returns true if all top-level keys are strings.
	 */
	private function hasOnlyStringKeys(array $data): bool
	{
		foreach (array_keys($data) as $key) {
			if (!is_string($key)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Extracts a scalar response field as string from the normalized raw result.
	 *
	 * The method first checks the top-level response array and then a nested
	 * "response" key if present.
	 *
	 * @param array<string, mixed> $rawResult
	 * Normalized raw result data.
	 *
	 * @param string $key
	 * Field name to resolve.
	 *
	 * @return string|null
	 * Returns the extracted scalar value as string or null if it is missing.
	 */
	private function extractStringValue(array $rawResult, string $key): ?string
	{
		if (isset($rawResult[$key]) && is_scalar($rawResult[$key])) {
			return (string)$rawResult[$key];
		}

		if (
			isset($rawResult['response']) &&
			is_array($rawResult['response']) &&
			isset($rawResult['response'][$key]) &&
			is_scalar($rawResult['response'][$key])
		) {
			return (string)$rawResult['response'][$key];
		}

		return null;
	}
}

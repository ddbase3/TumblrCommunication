<?php declare(strict_types=1);

namespace TumblrCommunication\Example;

use Base3\Api\IOutput;
use CommunicationFoundation\Api\ICommunicationPublisher;
use CommunicationFoundation\Model\CommunicationPost;
use CommunicationFoundation\Model\CommunicationPostMedia;
use CommunicationFoundation\Model\CommunicationPublishRequest;
use RuntimeException;
use Throwable;

/**
 * Simple end-to-end example for publishing one image post to Tumblr.
 *
 * This example intentionally keeps the setup small:
 * - one fixed local test image
 * - one fixed caption
 * - one fixed tag list
 * - target blog comes from Tumblr configuration
 */
class TumblrPostExample implements IOutput {

	/**
	 * Creates the example output with the Tumblr publisher service.
	 *
	 * @param TumblrPublisher $tumblrPublisher
	 * Concrete Tumblr publisher service resolved via DI.
	 */
	public function __construct(
		private readonly ICommunicationPublisher $tumblrPublisher
	) {}

	/**
	 * Returns the technical BASE3 output name.
	 *
	 * @return string
	 * Returns the lowercase technical name of this output.
	 */
	public static function getName(): string {
		return 'tumblrpostexample';
	}

	/**
	 * Executes the example publish flow and returns the result as text or HTML.
	 *
	 * The example uses a fixed image from the local userfiles directory and
	 * publishes it through the configured Tumblr account.
	 *
	 * @param string $out
	 * Requested output format.
	 *
	 * @param bool $final
	 * Unused finalization flag required by the interface.
	 *
	 * @return string
	 * Returns the formatted publish result.
	 */
	public function getOutput(string $out = 'html', bool $final = false): string {
		$imagePath = dirname(__DIR__, 4) . '/userfiles/example-image.jpg';

		try {
			$this->assertValidTestImage($imagePath);

			$media = new CommunicationPostMedia(
				$imagePath,
				'image',
				'image/jpeg',
				'Test image',
				'Test image for Tumblr publishing.',
				0
			);

			$post = new CommunicationPost(
				null,
				'Test post from BASE3 TumblrCommunication.',
				null,
				['ballet', 'photography', 'base3'],
				[$media],
				'en'
			);

			$request = new CommunicationPublishRequest($post);

			$result = $this->tumblrPublisher->publish($request);

			$lines = [
				'Tumblr publish finished.',
				'Success: ' . ($result->isSuccess() ? 'yes' : 'no'),
				'External ID: ' . ($result->getExternalId() ?? '-'),
				'External URL: ' . ($result->getExternalUrl() ?? '-'),
				'Published At: ' . ($result->getPublishedAt()?->format('c') ?? '-'),
				'Error: ' . ($result->getErrorMessage() ?? '-'),
				'Image: ' . $imagePath,
				'Raw Result:',
				$this->encodePrettyJson($result->getRawResult()),
			];

			return $this->formatOutput($lines, $out);
		} catch (Throwable $throwable) {
			$lines = [
				'Tumblr publish failed.',
				'Message: ' . $throwable->getMessage(),
				'Image: ' . $imagePath,
			];

			return $this->formatOutput($lines, $out);
		}
	}

	/**
	 * Returns a short help text for this example output.
	 *
	 * @return string
	 * Returns the help text.
	 */
	public function getHelp(): string {
		return 'Publishes a fixed test image from userfiles/lisa-khalimonenko-01.jpg to Tumblr.' . "\n";
	}

	/**
	 * Validates the configured local test image.
	 *
	 * The method checks whether the file exists, is readable and contains data.
	 *
	 * @param string $imagePath
	 * Absolute path to the local test image.
	 *
	 * @return void
	 *
	 * @throws RuntimeException
	 * Thrown if the test image is missing, unreadable or empty.
	 */
	private function assertValidTestImage(string $imagePath): void {
		if (!is_file($imagePath)) {
			throw new RuntimeException('Test image not found: ' . $imagePath);
		}

		if (!is_readable($imagePath)) {
			throw new RuntimeException('Test image is not readable: ' . $imagePath);
		}

		$fileSize = filesize($imagePath);
		if ($fileSize === false || $fileSize <= 0) {
			throw new RuntimeException('Test image is empty: ' . $imagePath);
		}
	}

	/**
	 * Formats the result either as plain text or as HTML.
	 *
	 * @param array<int, string> $lines
	 * Output lines to format.
	 *
	 * @param string $out
	 * Requested output format.
	 *
	 * @return string
	 * Returns the formatted output.
	 */
	private function formatOutput(array $lines, string $out): string {
		$text = implode("\n", $lines);

		if ($out === 'html') {
			return '<pre>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
		}

		return $text;
	}

	/**
	 * Encodes data as pretty printed JSON for debugging output.
	 *
	 * @param array<string, mixed> $data
	 * Data to encode.
	 *
	 * @return string
	 * Returns the formatted JSON string.
	 */
	private function encodePrettyJson(array $data): string {
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		if (is_string($json)) {
			return $json;
		}

		return '{}';
	}
}

# TumblrCommunication

BASE3 plugin for publishing platform-neutral communication posts to Tumblr.

`TumblrCommunication` implements the contracts from `CommunicationFoundation` and provides a reusable Tumblr publisher that can send generic communication posts to a Tumblr blog.

The plugin is designed to keep your application code provider-neutral. Your application builds generic communication models, and `TumblrCommunication` translates them into Tumblr API calls.

---

## Current Status

The plugin is functional and already able to publish posts to Tumblr from within a BASE3 environment.

At the moment, the implementation is intentionally small and focused on the core publishing flow:

* configuration is loaded via BASE3 `IConfiguration`
* publishing is performed through `Base3\TumblrCommunication\Service\TumblrPublisher`
* the publisher implements `ICommunicationPublisher`
* application code can stay provider-neutral by depending on the communication contracts

The plugin does **not** contain any AI functionality such as caption generation, image analysis or content creation. Those concerns belong into higher-level application services or other plugins.

---

## Features

* Publish platform-neutral posts to Tumblr
* Uses `CommunicationFoundation` contracts and models
* Supports text posts
* Supports link posts
* Supports image posts
* Supports video posts
* Supports provider-neutral tags
* Supports provider-specific Tumblr options through `providerOptions`
* Loads Tumblr credentials and defaults from BASE3 configuration
* Can be integrated into existing BASE3 services, outputs, jobs, flows or agents

---

## Goals

The main goal of this plugin is to make Tumblr publishing a concrete implementation detail and not an application-wide dependency.

Your code should work against the general communication contracts:

* `ICommunicationPost`
* `ICommunicationPostMedia`
* `ICommunicationPublishRequest`
* `ICommunicationPublishResult`
* `ICommunicationPublisher`

This makes it possible to add other providers later without changing your core posting logic.

---

## Architecture

The plugin follows a simple separation of responsibilities:

* `CommunicationFoundation` defines the generic contracts and core models
* `TumblrCommunication` implements the Tumblr-specific publishing logic
* your application creates generic communication objects
* the Tumblr publisher converts them into Tumblr-specific API requests

A typical flow looks like this:

1. Create a `CommunicationPost`
2. Add tags and media
3. Wrap the post in a `CommunicationPublishRequest`
4. Pass the request to an `ICommunicationPublisher`
5. Receive a normalized `ICommunicationPublishResult`

In a Tumblr-specific setup, the injected publisher implementation is usually `Base3\TumblrCommunication\Service\TumblrPublisher`.

---

## Requirements

* PHP 8.x
* BASE3 plugin environment
* `CommunicationFoundation`
* a valid Tumblr application and access credentials
* a Tumblr blog that can receive posts
* the Tumblr PHP client library available through Composer/autoload

The current implementation uses the Tumblr PHP client library and its dependencies.

---

## Installation

Install the plugin into your BASE3 plugin directory:

```text
plugin/TumblrCommunication/
```

Make sure the following plugin is available as well:

```text
plugin/CommunicationFoundation/
```

In addition, the Tumblr PHP library must be available in your project setup via Composer.

How exactly this is loaded depends on your BASE3 installation and autoload setup. In a typical BASE3 installation, Composer-based dependencies are already available through the project autoloading setup.

---

## Plugin Structure

A typical structure of the current plugin looks like this:

```text
TumblrCommunication/
├── src/
│   ├── Example/
│   └── Service/
├── composer.json
└── README.md
```

Typical classes include:

* `Base3\TumblrCommunication\Service\TumblrPublisher`
* `Base3\TumblrCommunication\Example\TumblrPostExample`

The generic contracts, models and base exceptions are intentionally provided by `CommunicationFoundation` and not duplicated inside this plugin.

---

## Configuration

The plugin reads its Tumblr configuration from the BASE3 configuration service via the configuration group:

```ini
[tumblr]
tumblrConsumerKey = "..."
tumblrConsumerSecret = "..."
tumblrToken = "..."
tumblrTokenSecret = "..."
blogIdentifier = "exampleblog.tumblr.com"
```

The following values are required:

* `tumblrConsumerKey`
* `tumblrConsumerSecret`
* `tumblrToken`
* `tumblrTokenSecret`
* `blogIdentifier`

The configured `blogIdentifier` acts as the default target blog.

If a publish request contains its own target via `ICommunicationPublishRequest::getTarget()`, that explicit target overrides the configured default.

The recommended approach is to keep credentials outside of application code.

---

## Supported Content

The plugin is built around the generic communication contracts, but currently targets Tumblr publishing.

### Supported post content

* caption text
* optional title
* optional link
* tags
* media attachments
* optional language metadata

### Supported media types

* images
* videos

Each media item can carry metadata such as:

* source path or URL
* media type
* MIME type
* title
* alt text
* sort order
* thumbnail source

---

## Current Tumblr Mapping

The current implementation maps generic communication data to Tumblr roughly as follows:

* generic text post without media -> Tumblr text post
* generic post with link and without media -> Tumblr link post
* generic post with one or more images -> Tumblr photo post
* generic post with exactly one video -> Tumblr video post
* generic tags -> Tumblr tag string
* request target -> Tumblr blog identifier
* visibility / provider options -> Tumblr state, where supported

### Current implementation details

Text posts:

* use Tumblr type `text`
* map the generic title to Tumblr `title`
* map caption and optional link into the text body

Link posts:

* use Tumblr type `link`
* map the generic link to Tumblr `url`
* map caption to Tumblr `description`
* map title to Tumblr `title` when present

Photo posts:

* use Tumblr type `photo`
* support one local file
* support multiple local image files
* support one remote image URL
* include title, caption and link as combined caption text where applicable

Video posts:

* use Tumblr type `video`
* support one local video file
* support one remote video URL via embed-style mapping

---

## Current Limitations

The current implementation is intentionally small and predictable.

The following limitations currently apply:

* mixed image and video posts in one request are not supported
* more than one video in a single post is not supported
* `publishAt` is currently not supported
* multiple remote image URLs in one multi-image post are not supported
* the returned external URL may be unavailable if Tumblr does not return it

If `publishAt` is set, the current implementation rejects the request instead of silently guessing Tumblr scheduling behavior.

---

## Basic Usage

The recommended application-facing usage is provider-neutral.

Your application should usually depend on `ICommunicationPublisher` and not directly on the Tumblr service class.

```php
<?php declare(strict_types=1);

use Base3\CommunicationFoundation\Api\ICommunicationPublisher;
use Base3\CommunicationFoundation\Model\CommunicationPost;
use Base3\CommunicationFoundation\Model\CommunicationPostMedia;
use Base3\CommunicationFoundation\Model\CommunicationPublishRequest;

class ExamplePostingService
{
	public function __construct(
		private readonly ICommunicationPublisher $publisher
	) {}

	public function publishImage(string $imagePath): void
	{
		$media = [
			new CommunicationPostMedia(
				$imagePath,
				'image',
				'image/jpeg',
				'Test image',
				'Test image for Tumblr publishing.',
				0
			)
		];

		$post = new CommunicationPost(
			null,
			'Test post from BASE3 TumblrCommunication.',
			null,
			['ballet', 'photography', 'base3'],
			$media,
			'en'
		);

		$request = new CommunicationPublishRequest($post);

		$result = $this->publisher->publish($request);

		if (!$result->isSuccess()) {
			throw new \RuntimeException($result->getErrorMessage() ?? 'Tumblr publish failed.');
		}
	}
}
```

If you want to override the configured default target blog, pass the target directly in the publish request:

```php
$request = new CommunicationPublishRequest(
	$post,
	'anotherblog.tumblr.com'
);
```

---

## Example Output

The plugin currently includes a runnable example output:

* `Base3\TumblrCommunication\Example\TumblrPostExample`

This example:

* injects `ICommunicationPublisher`
* creates a `CommunicationPostMedia`
* creates a `CommunicationPost`
* wraps the post in a `CommunicationPublishRequest`
* publishes the request through the injected publisher
* prints the normalized result

The example expects the local test file:

```text
userfiles/lisa-khalimonenko-01.jpg
```

This is useful as a small end-to-end verification inside a BASE3 installation.

---

## Publishing Model

The plugin uses two layers.

### Content layer

The content layer describes the post itself:

* caption
* title
* link
* tags
* media
* language

### Delivery layer

The delivery layer describes how the post should be published:

* target blog
* optional publish date
* optional reply target
* visibility
* provider options

This separation keeps the design reusable across platforms.

---

## Media Handling

Media can be provided as:

* local filesystem paths
* remote URLs in supported cases

### Current behavior

Images:

* one local file is supported
* one remote URL is supported
* multiple local files are supported
* multiple remote image URLs are currently not supported in one post

Videos:

* one local file is supported
* one remote URL is supported
* multiple videos in one request are currently not supported

Recommendations:

* always provide a correct MIME type when possible
* provide alt text for accessibility
* provide media in the final intended order
* keep source files accessible at publish time

---

## Tags

Tags are intentionally modeled as platform-neutral strings.

Recommended input:

```php
['ballet', 'dance', 'studio']
```

Do not force provider syntax into the generic model unless your application explicitly wants that behavior.

The Tumblr publisher normalizes tags before sending them to Tumblr. Leading `#` characters are stripped so that both of the following inputs can work, although plain values are preferred:

```php
['ballet', 'dance']
['#ballet', '#dance']
```

Plain tag values are the recommended format.

---

## Provider Options

`CommunicationPublishRequest` supports a provider-specific option map:

```php
[
	'state' => 'published',
	'slug' => 'ballet-rehearsal-session',
]
```

This exists as an extension point for Tumblr-specific behavior without polluting the generic contracts.

The current implementation already uses this extension point, for example for Tumblr state handling.

Typical examples of provider-specific options may include:

* state
* slug
* queue behavior
* format flags
* provider-specific metadata

Only the Tumblr publisher should interpret these options.

---

## Visibility and State Handling

The current publisher can map generic visibility values to Tumblr state values.

Currently recognized visibility values are:

* `public` -> `published`
* `published` -> `published`
* `private` -> `private`
* `draft` -> `draft`
* `queue` -> `queue`

If `providerOptions['state']` is explicitly set, that value takes precedence over the generic visibility mapping.

---

## Result Handling

Publishing returns a normalized `ICommunicationPublishResult`.

Typical result data includes:

* success flag
* external Tumblr post ID
* external post URL, if available
* published timestamp, if available
* error message
* raw normalized result data

Example:

```php
if ($result->isSuccess()) {
	$postId = $result->getExternalId();
	$postUrl = $result->getExternalUrl();
} else {
	$error = $result->getErrorMessage();
}
```

Note that Tumblr may not always return all fields in the same shape. In particular, the external URL may be unavailable even when publishing succeeded.

---

## Error Handling

The plugin uses the generic exceptions from `CommunicationFoundation`.

Typical exception types include:

* `CommunicationException`
* `CommunicationValidationException`
* `CommunicationPublishException`

Recommended handling strategy:

* validate input before publishing
* catch provider exceptions near the integration boundary
* log normalized raw result data where useful
* expose only user-friendly messages to UI layers

Example:

```php
<?php declare(strict_types=1);

use Base3\CommunicationFoundation\Exception\CommunicationPublishException;

try {
	$result = $publisher->publish($request);
} catch (CommunicationPublishException $e) {
	error_log($e->getMessage());
}
```

---

## Example: Automated Posting Workflow

A common use case is an automated content pipeline:

1. generate or collect images
2. generate caption text in another layer
3. build a `CommunicationPost`
4. create a `CommunicationPublishRequest`
5. publish via `ICommunicationPublisher`
6. store the external Tumblr post ID for later reference

This makes the plugin a good fit for scheduled social posting pipelines, content bots and media workflows.

The actual text generation or content preparation should happen outside of this plugin.

---

## Integration in BASE3

The plugin is intended to work cleanly inside a BASE3-based application architecture.

Typical integration patterns:

* inject `ICommunicationPublisher` via DI
* let DI resolve `TumblrPublisher` as the concrete provider implementation
* load credentials from BASE3 configuration
* build generic posts in controllers, jobs, agents or outputs
* keep provider-specific logic out of business code

A common application-level service might look like this:

```php
<?php declare(strict_types=1);

namespace App\Service;

use Base3\CommunicationFoundation\Api\ICommunicationPublisher;
use Base3\CommunicationFoundation\Model\CommunicationPost;
use Base3\CommunicationFoundation\Model\CommunicationPostMedia;
use Base3\CommunicationFoundation\Model\CommunicationPublishRequest;

class TumblrPostingService
{
	public function __construct(
		private readonly ICommunicationPublisher $publisher
	) {}

	public function publishImage(string $target, string $imagePath, string $caption, array $tags = []): void
	{
		$media = [
			new CommunicationPostMedia(
				$imagePath,
				'image',
				'image/jpeg',
				null,
				null,
				10,
				null
			)
		];

		$post = new CommunicationPost(
			null,
			$caption,
			null,
			$tags,
			$media,
			'en'
		);

		$request = new CommunicationPublishRequest(
			$post,
			$target
		);

		$this->publisher->publish($request);
	}
}
```

---

## Design Notes

### Why not post directly with a Tumblr-specific data structure?

Because provider-specific models quickly leak into the rest of the application.

Using `CommunicationFoundation` gives you:

* a stable abstraction
* easier testing
* easier future provider support
* less vendor lock-in in your application code

### Why separate post and publish request?

Because content and delivery are different concerns.

The same post content can be:

* published to multiple targets
* scheduled differently
* published with different provider options
* reused across different providers

---

## Known Runtime Notes

Depending on the exact Tumblr PHP dependency versions in use, PHP 8.2 may show deprecation notices related to older OAuth dependency code.

If publishing still succeeds, that warning does not necessarily mean the actual Tumblr post failed. It indicates that one of the underlying dependencies still uses older PHP patterns.

This is a dependency/runtime compatibility topic and not part of the generic communication model.

---

## Best Practices

* keep credentials out of source code
* use generic communication models in your application layer
* inject the publisher via the communication interface where possible
* keep Tumblr-specific options isolated in `providerOptions`
* always provide accessible alt text for images when available
* log provider result data for debugging
* store returned external IDs if later synchronization is needed

---

## Future Extensions

The architecture is intentionally designed for growth.

Natural next steps include:

* additional communication providers
* update and delete operations
* richer Tumblr-specific options
* scheduling support via a later dedicated Tumblr implementation
* status synchronization
* cross-posting to multiple platforms
* reusable communication orchestration services

---

## Development Notes

If you extend the plugin, keep the following principle in mind:

> `CommunicationFoundation` owns the generic contract.
> `TumblrCommunication` owns the Tumblr-specific implementation.

Do not move provider-specific behavior into the foundation layer unless it is truly generic.

---

## Troubleshooting

### Publish fails with authentication errors

Check:

* consumer key
* consumer secret
* token
* token secret
* blog identifier
* Tumblr app permissions

### Media upload fails

Check:

* source path exists
* file is readable
* file is not empty
* MIME type matches the file
* file size fits provider limits
* media type is supported by the publisher
* unsupported mixed media combinations are not used

### Tags do not appear as expected

Check:

* input tags are provided as plain values
* Tumblr publisher normalization logic
* Tumblr API response and normalized raw result

### `publishAt` causes an exception

This is expected in the current implementation.

The current publisher does not support scheduling yet and rejects requests with `publishAt` set.

---

## Contributing

Contributions should preserve the separation between generic communication contracts and provider-specific implementation logic.

When adding new features:

* prefer extending provider behavior through the Tumblr plugin
* avoid adding Tumblr-specific concerns to `CommunicationFoundation`
* keep the public contracts stable whenever possible

---

## License

See the project license of your BASE3 installation or repository setup.

---

## Summary

`TumblrCommunication` gives BASE3 a clean Tumblr publishing integration built on top of provider-neutral communication contracts.

It is intended for applications that want to:

* publish to Tumblr in a structured way
* keep their posting code reusable
* prepare for future multi-platform communication support
* integrate posting into automated content workflows

The current implementation already supports real Tumblr publishing and provides a small, working BASE3 example for end-to-end verification.


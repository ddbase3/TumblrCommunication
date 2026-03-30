<?php declare(strict_types=1);

namespace TumblrCommunication;

use Base3\Api\IContainer;
use Base3\Api\IPlugin;
use Base3\Configuration\Api\IConfiguration;
use CommunicationFoundation\Api\ICommunicationPublisher;
use TumblrCommunication\Service\TumblrPublisher;

class TumblrCommunicationPlugin implements IPlugin {

	public function __construct(private readonly IContainer $container) {}

	// Implementation of IBase

	public static function getName(): string {
		return 'tumblrcommunicationplugin';
	}

	// Implementation of IPlugin

	public function init() {

		$this->container
			->set(self::getName(), $this, IContainer::SHARED)

			->set(ICommunicationPublisher::class, fn($c) => new TumblrPublisher($c->get(IConfiguration::class)), IContainer::SHARED | IContainer::NOOVERWRITE);
	}
}

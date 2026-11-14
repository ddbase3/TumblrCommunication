<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of TumblrCommunication for BASE3 Framework.
 *
 * TumblrCommunication delivers communication posts to Tumblr.
 * Its publisher implements CommunicationFoundation contracts.
 * Provider-specific delivery uses the existing service APIs.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de
 ***********************************************************************/


namespace Base3\TumblrCommunication;

use Base3\CoreFoundation\Api\IContainer;
use Base3\CoreFoundation\Api\IPlugin;
use Base3\CoreFoundation\Configuration\Api\IConfiguration;
use Base3\CommunicationFoundation\Api\ICommunicationPublisher;
use Base3\TumblrCommunication\Service\TumblrPublisher;

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

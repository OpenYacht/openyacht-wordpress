<?php

declare(strict_types=1);

namespace OpenYacht;

use OpenYacht\Federation\CopyMediaRepository;
use OpenYacht\Federation\CopyRepository;
use OpenYacht\Federation\InboundVerification;
use OpenYacht\Federation\KeyEncryption;
use OpenYacht\Federation\KeyManager;
use OpenYacht\Federation\Logger;
use OpenYacht\Federation\PartnerRepository;
use OpenYacht\Federation\PartnerService;
use OpenYacht\Federation\SignedClient;
use OpenYacht\Federation\Signer;
use OpenYacht\Federation\SyncService;
use OpenYacht\Federation\Verifier;
use OpenYacht\Federation\WellKnownClient;
use OpenYacht\Federation\WellKnownDocument;
use OpenYacht\Federation\WpdbCopyMediaRepository;
use OpenYacht\Federation\WpdbCopyRepository;
use OpenYacht\Federation\WpdbKeyRepository;
use OpenYacht\Federation\WpdbLogger;
use OpenYacht\Federation\WpdbPartnerRepository;
use OpenYacht\Media\ImageFetcher;
use OpenYacht\Media\MediaService;
use OpenYacht\Media\Storage;
use OpenYacht\Media\StorageFactory;
use OpenYacht\Media\WpRenditionGenerator;

/**
 * Composition root: builds the service graph over the live $wpdb once per
 * request. Everything here is constructor-injected everywhere else, so
 * tests never touch this class.
 */
final class Services
{
    /** @var array<string, object> */
    private static array $cache = [];

    public static function keyManager(): KeyManager
    {
        return self::$cache[__FUNCTION__] ??= new KeyManager(
            new WpdbKeyRepository(self::wpdb(), KeyEncryption::fromWpSalts()),
        );
    }

    public static function partners(): PartnerRepository
    {
        return self::$cache[__FUNCTION__] ??= new WpdbPartnerRepository(self::wpdb());
    }

    public static function copies(): CopyRepository
    {
        return self::$cache[__FUNCTION__] ??= new WpdbCopyRepository(self::wpdb());
    }

    public static function logger(): Logger
    {
        return self::$cache[__FUNCTION__] ??= new WpdbLogger(self::wpdb());
    }

    public static function partnerService(): PartnerService
    {
        return self::$cache[__FUNCTION__] ??= new PartnerService(
            self::partners(),
            new WellKnownClient(),
            self::logger(),
        );
    }

    public static function syncService(): SyncService
    {
        return self::$cache[__FUNCTION__] ??= new SyncService(
            new SignedClient(new Signer(self::keyManager())),
            self::partners(),
            self::copies(),
        );
    }

    public static function wellKnownDocument(): WellKnownDocument
    {
        return self::$cache[__FUNCTION__] ??= new WellKnownDocument(self::keyManager());
    }

    public static function copyMedia(): CopyMediaRepository
    {
        return self::$cache[__FUNCTION__] ??= new WpdbCopyMediaRepository(self::wpdb());
    }

    public static function storage(): Storage
    {
        return self::$cache[__FUNCTION__] ??= StorageFactory::make();
    }

    public static function mediaService(): MediaService
    {
        return self::$cache[__FUNCTION__] ??= new MediaService(
            self::storage(),
            new ImageFetcher(),
            new WpRenditionGenerator(),
            self::copyMedia(),
            self::logger(),
        );
    }

    public static function inboundVerification(): InboundVerification
    {
        return self::$cache[__FUNCTION__] ??= new InboundVerification(
            new Verifier(),
            self::partners(),
            self::partnerService(),
            self::logger(),
        );
    }

    private static function wpdb(): \wpdb
    {
        global $wpdb;

        return $wpdb;
    }
}

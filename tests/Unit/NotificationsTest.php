<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit;

use Brain\Monkey\Functions;
use OpenYacht\Federation\Partner;
use OpenYacht\Federation\TrustLevel;
use OpenYacht\Notifications;

final class NotificationsTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('get_bloginfo')->justReturn('Test Node');
        Functions\when('get_option')->justReturn('admin@node.test');
        Functions\when('admin_url')->alias(static fn (string $path): string => 'https://node.test/wp-admin/' . $path);
        Functions\when('add_query_arg')->alias(static fn (array $args, string $url): string => $url . '?page=' . $args['page']);
        Functions\when('apply_filters')->alias(static fn (string $hook, $value) => $value);
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $message): bool {
            $this->sent[] = [$to, $subject, $message];

            return true;
        });
    }

    private function partner(): Partner
    {
        return new Partner(7, 'partner.test', 'c6d45cc6-dd45-4f8f-b588-53738473a183', [], null, null, TrustLevel::Provisional, null, null, null, 0, null, null);
    }

    public function testFirstContactMailsTheAdminWithApprovalLink(): void
    {
        (new Notifications())->partnerFirstContact($this->partner());

        self::assertCount(1, $this->sent);
        [$to, $subject, $message] = $this->sent[0];
        self::assertSame('admin@node.test', $to);
        self::assertStringContainsString('partner.test', $subject);
        self::assertStringContainsString('provisional', $message);
        self::assertStringContainsString('page=openyacht-partners', $message);
    }

    public function testUuidChangeMailsATakeoverWarning(): void
    {
        (new Notifications())->partnerUuidChanged($this->partner());

        self::assertCount(1, $this->sent);
        self::assertStringContainsString('identity changed', $this->sent[0][1]);
        self::assertStringContainsString('takeover', $this->sent[0][2]);
    }

    public function testEmptyFilteredRecipientSilencesMail(): void
    {
        Functions\when('apply_filters')->alias(static fn (string $hook, $value) => '');

        (new Notifications())->partnerFirstContact($this->partner());

        self::assertSame([], $this->sent);
    }
}

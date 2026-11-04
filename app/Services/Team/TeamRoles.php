<?php

namespace App\Services\Team;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Roles of the people who work for an account (sub-users). The roles follow the model of Meta / Instagram account roles
 * (admin, content creator, moderator, advertiser, analyst) plus the "attendant" of helpdesks such as Chatwoot:
 *
 *   admin     everything except billing and the owner's own profile
 *   approver  reviews campaign messages, chooses the audience (score + channels) and approves / rejects   (~ moderator)
 *   creator   writes campaigns and messages and submits them for approval                                (~ content creator)
 *   sender    launches approved campaigns                                                                (~ advertiser)
 *   agent     answers conversations only                                                                 (helpdesk agent)
 *   analyst   read-only: statistics, ranking, campaign reports                                           (~ analyst)
 *
 * Members are denied by default: a route or tool that is not listed here is closed to them.
 */
final class TeamRoles
{
    public const ADMIN = 'admin';
    public const APPROVER = 'approver';
    public const CREATOR = 'creator';
    public const SENDER = 'sender';
    public const AGENT = 'agent';
    public const ANALYST = 'analyst';

    /** @return array<string,string[]> role => permissions */
    public static function matrix(): array
    {
        return [
            self::ADMIN => ['chat', 'contacts.view', 'contacts.manage', 'campaigns.view', 'campaigns.manage', 'campaigns.approve', 'campaigns.send', 'reports', 'channels', 'ai', 'forms', 'deals', 'tasks', 'team'],
            self::APPROVER => ['contacts.view', 'campaigns.view', 'campaigns.approve', 'reports'],
            self::CREATOR => ['contacts.view', 'contacts.manage', 'campaigns.view', 'campaigns.manage', 'forms'],
            self::SENDER => ['contacts.view', 'campaigns.view', 'campaigns.send'],
            self::AGENT => ['chat', 'contacts.view', 'deals', 'tasks'],
            self::ANALYST => ['contacts.view', 'campaigns.view', 'reports'],
        ];
    }

    /** @return string[] */
    public static function roles(): array
    {
        return array_keys(self::matrix());
    }

    public static function valid(?string $role): bool
    {
        return $role !== null && isset(self::matrix()[$role]);
    }

    /** @return array<string,string> role => label (translated) */
    public static function labels(): array
    {
        return [
            self::ADMIN => __('Administrator'),
            self::APPROVER => __('Approver'),
            self::CREATOR => __('Campaign creator'),
            self::SENDER => __('Sender'),
            self::AGENT => __('Attendant'),
            self::ANALYST => __('Analyst'),
        ];
    }

    /** @return array<string,string> role => what it can do (translated) */
    public static function descriptions(): array
    {
        return [
            self::ADMIN => __('Everything except plan, billing and the account owner profile.'),
            self::APPROVER => __('Reviews campaign messages, chooses the audience (score and channels) and approves or rejects.'),
            self::CREATOR => __('Writes campaigns and messages and submits them for approval.'),
            self::SENDER => __('Launches approved campaigns.'),
            self::AGENT => __('Answers conversations only.'),
            self::ANALYST => __('Read-only access to statistics, ranking and reports.'),
        ];
    }

    public static function allows(?string $role, string $permission): bool
    {
        return $role !== null && in_array($permission, self::matrix()[$role] ?? [], true);
    }

    // ---- what a request / tool needs ------------------------------------------------------------------------------------

    /** Web route names, first match wins. A list = any of those permissions is enough. */
    private const WEB = [
        ['any', ['logout.perform', 'notifications.clear', 'home.index', 'language.switch']],
        ['chat', ['chat.*']],
        ['contacts.view', ['contacts.index', 'contacts.show', 'contacts.photo']],
        ['team', ['contacts.clear']],
        ['contacts.manage', ['contacts.*']],
        ['campaigns.view', ['campaigns.index', 'campaigns.show', 'campaigns.report', 'campaign-items.index', 'campaign-items.show', 'campaign-items.logs', 'whatsapp-jobs.index']],
        ['campaigns.send', ['campaign-items.generate', 'campaign-items.generateAll', 'campaign-items.send', 'whatsapp-jobs.*']],
        ['campaigns.manage', ['campaigns.*', 'campaign-items.*']],
        [['campaigns.approve', 'campaigns.send', 'campaigns.manage'], ['approvals.preview']],
        ['campaigns.approve', ['approvals.*']],
        ['reports', ['stats.*']],
        ['forms', ['forms.*']],
        ['deals', ['pipeline.*']],
        ['tasks', ['tasks.*']],
        ['channels', ['telegram.*', 'tiktok.*', 'facebook.*', 'email.*', 'instagram.*', 'instances.*', 'whatsapp.*']],
        ['ai', ['ai.*']],
        ['team', ['team.*']],
    ];

    /** API paths (after "api/v1/"), by method kind: R = read, W = write. First match wins. */
    private const API = [
        ['any', ['auth/me', 'auth/logout', 'notifications*']],
        ['chat', ['conversations*']],
        ['contacts.view', ['contacts/ranking', 'contacts/*/channels'], 'R'],
        ['contacts.view', ['contacts', 'contacts/*'], 'R'],
        ['contacts.manage', ['contacts*']],
        ['campaigns.send', ['campaign-items/*/generate-all', 'campaign-items/*/generate-test', 'whatsapp-jobs*']],
        [['campaigns.approve', 'campaigns.send', 'campaigns.manage'], ['campaign-items/*/audience']],
        ['campaigns.approve', ['campaign-items/*/approve', 'campaign-items/*/reject', 'approvals*']],
        ['campaigns.manage', ['campaign-items/*/submit']],
        ['campaigns.view', ['campaigns*', 'campaign-items*'], 'R'],
        ['campaigns.manage', ['campaigns*', 'campaign-items*']],
        ['reports', ['dashboard', 'channels/stats'], 'R'],
        ['channels', ['channels*', 'instances*']],
        ['team', ['team*']],
        ['any', ['tools*']], // each tool is checked against the role by ToolDispatcher
    ];

    /** MCP tool => permission. Not listed = denied for members. */
    private const TOOLS = [
        'any' => ['list_notifications', 'mark_notifications_read'],
        'chat' => ['list_conversations', 'get_conversation_messages', 'send_conversation_message', 'mark_conversation_read', 'pause_conversation_ai'],
        'contacts.view' => ['list_contacts', 'get_contact', 'get_contact_ranking', 'get_contact_channels'],
        'contacts.manage' => ['create_contact', 'update_contact', 'delete_contact', 'bulk_delete_contacts', 'bulk_update_contact_status', 'sync_contact_photo', 'rescore_contact', 'link_contacts', 'unlink_contact', 'check_unresponsive_contacts'],
        'team' => ['clear_contacts', 'list_team_members', 'add_team_member', 'update_team_member', 'remove_team_member'],
        'campaigns.view' => ['list_campaigns', 'get_campaign', 'get_campaign_report', 'list_campaign_items', 'get_campaign_item', 'list_send_jobs'],
        'campaigns.manage' => ['create_campaign', 'update_campaign', 'delete_campaign', 'create_campaign_item', 'update_campaign_item', 'delete_campaign_item', 'submit_campaign_item_for_approval'],
        'campaigns.send' => ['generate_test_send', 'launch_campaign', 'retry_send_job', 'bulk_retry_send_jobs', 'bulk_delete_send_jobs'],
        'campaigns.approve' => ['approve_campaign_item', 'reject_campaign_item', 'list_pending_approvals'],
        'any-audience' => ['preview_campaign_audience'],
        'reports' => ['get_dashboard', 'get_channel_stats'],
        'channels' => ['list_instances', 'get_instance', 'create_instance', 'delete_instance', 'get_instance_qr', 'check_instance_connection', 'toggle_instance_warmup', 'sync_instance_contacts',
            'list_channels', 'connect_telegram_bot', 'connect_instagram_account', 'connect_facebook_page', 'connect_email_account', 'get_tiktok_connect_url', 'renew_channel_connection', 'remove_channel_account'],
    ];

    /** @return string|string[]|null  'any' | permission | any-of list | null (denied) */
    public static function requiredFor(Request $request): string|array|null
    {
        $route = $request->route();
        $name = $route ? $route->getName() : null;

        if ($name) {
            foreach (self::WEB as [$perm, $patterns]) {
                if (Str::is($patterns, $name)) {
                    return $perm;
                }
            }
        }

        $path = trim($request->path(), '/');
        if (str_starts_with($path, 'api/v1/')) {
            $path = substr($path, 7);
            $kind = $request->isMethodSafe() ? 'R' : 'W';
            foreach (self::API as $row) {
                [$perm, $patterns] = $row;
                if (isset($row[2]) && $row[2] !== $kind) {
                    continue;
                }
                if (Str::is($patterns, $path)) {
                    return $perm;
                }
            }
        }

        return null;
    }

    /** @return string|string[]|null */
    public static function requiredForTool(string $tool): string|array|null
    {
        foreach (self::TOOLS as $perm => $tools) {
            if (in_array($tool, $tools, true)) {
                return $perm === 'any-audience' ? ['campaigns.approve', 'campaigns.send', 'campaigns.manage'] : $perm;
            }
        }

        return null;
    }
}

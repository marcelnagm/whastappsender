<?php

namespace App\Services\Api;

class ToolRegistry
{
    /**
     * OpenAI-compatible function definitions for AI agent tool calling.
     */
    public static function definitions(bool $isAdmin = false): array
    {
        $tools = [
            // Dashboard & Profile
            self::tool('get_dashboard', 'Get dashboard stats: contacts count, delivery rate, error rate, connected instances.'),
            self::tool('get_profile', 'Get current user profile and AI agent settings.'),
            self::tool('update_profile', 'Update user profile and AI agent settings.', [
                'name' => ['type' => 'string', 'description' => 'Display name'],
                'email' => ['type' => 'string', 'description' => 'Email address'],
                'username' => ['type' => 'string', 'description' => 'Username'],
                'password' => ['type' => 'string', 'description' => 'New password'],
                'password_confirmation' => ['type' => 'string', 'description' => 'Password confirmation'],
                'ai_enabled' => ['type' => 'boolean', 'description' => 'Enable AI auto-reply'],
                'ai_mode' => ['type' => 'string', 'enum' => ['off', 'assist', 'auto'], 'description' => 'AI mode'],
                'ai_model' => ['type' => 'string', 'description' => 'LLM model name'],
                'ai_temperature' => ['type' => 'number', 'description' => 'LLM temperature 0-2'],
                'ai_max_tokens' => ['type' => 'integer', 'description' => 'Max tokens per reply'],
                'ai_system_prompt' => ['type' => 'string', 'description' => 'System prompt for AI agent'],
                'ai_business_hours_only' => ['type' => 'boolean', 'description' => 'Reply only during business hours'],
            ]),

            // Contacts
            self::tool('list_contacts', 'List contacts with optional search and pagination.', [
                'search' => ['type' => 'string', 'description' => 'Search by name, phone or email'],
                'page' => ['type' => 'integer', 'description' => 'Page number'],
                'per_page' => ['type' => 'integer', 'description' => 'Items per page (max 100)'],
                'tier' => ['type' => 'string', 'enum' => ['hot', 'warm', 'cold', 'frozen'], 'description' => 'Only contacts of this engagement tier'],
                'status' => ['type' => 'string', 'description' => 'Filter by status (ativo, inativo, no-whatsapp, telegram, instagram, facebook, tiktok)'],
                'sort' => ['type' => 'string', 'enum' => ['score'], 'description' => 'score = best engagement first'],
            ]),
            self::tool('get_contact', 'Get a contact by ID.', [
                'contact_id' => ['type' => 'integer', 'description' => 'Contact ID'],
            ], ['contact_id']),
            self::tool('create_contact', 'Create a new contact.', [
                'name' => ['type' => 'string', 'description' => 'Contact name'],
                'contact' => ['type' => 'string', 'description' => 'Phone number'],
                'email' => ['type' => 'string', 'description' => 'Email address'],
                'status' => ['type' => 'string', 'enum' => ['ativo', 'inativo', 'no-whatsapp']],
            ], ['name', 'contact']),
            self::tool('update_contact', 'Update an existing contact.', [
                'contact_id' => ['type' => 'integer', 'description' => 'Contact ID'],
                'name' => ['type' => 'string'],
                'contact' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['ativo', 'inativo', 'no-whatsapp']],
                'score' => ['type' => 'integer', 'description' => 'Set the score by hand (0-100); it stops being recalculated'],
                'score_locked' => ['type' => 'boolean', 'description' => 'false hands the score back to the automatic calculation'],
            ], ['contact_id']),
            self::tool('delete_contact', 'Delete a contact.', [
                'contact_id' => ['type' => 'integer', 'description' => 'Contact ID'],
            ], ['contact_id']),
            self::tool('bulk_delete_contacts', 'Delete multiple contacts.', [
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Contact IDs'],
            ], ['ids']),
            self::tool('bulk_update_contact_status', 'Update status of multiple contacts.', [
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'status' => ['type' => 'string', 'enum' => ['ativo', 'inativo', 'no-whatsapp']],
            ], ['ids', 'status']),
            self::tool('clear_contacts', 'Delete all contacts for the current user.'),
            self::tool('sync_contact_photo', 'Sync contact profile photo from WhatsApp.', [
                'contact_id' => ['type' => 'integer'],
            ], ['contact_id']),

            self::tool('get_contact_ranking', 'Contacts ranked by engagement score (0-100) with tier (hot/warm/cold/frozen) and what the score is made of.', [
                'tier' => ['type' => 'string', 'enum' => ['hot', 'warm', 'cold', 'frozen']],
                'limit' => ['type' => 'integer', 'description' => 'Max 100, default 20'],
            ]),
            self::tool('rescore_contact', 'Recalculate the automatic score of a contact now (manual scores are kept).', [
                'contact_id' => ['type' => 'integer'],
            ], ['contact_id']),
            self::tool('get_contact_channels', 'Show the Telegram/Instagram/Facebook/TikTok identities linked to a contact and all their conversations.', [
                'contact_id' => ['type' => 'integer'],
            ], ['contact_id']),
            self::tool('link_contacts', 'Link a Telegram/Instagram/Facebook/TikTok contact to a WhatsApp contact (same person).', [
                'contact_id' => ['type' => 'integer'],
                'linked_contact_id' => ['type' => 'integer'],
            ], ['contact_id', 'linked_contact_id']),
            self::tool('unlink_contact', 'Remove the link of a Telegram/Instagram/Facebook/TikTok contact.', [
                'contact_id' => ['type' => 'integer'],
            ], ['contact_id']),
            self::tool('check_unresponsive_contacts', 'Deactivate contacts whose WhatsApp messages were never delivered for X hours (default from config). Use dry_run to only list.', [
                'dry_run' => ['type' => 'boolean'],
                'hours' => ['type' => 'integer', 'description' => 'Override the configured period'],
            ]),

            // Channels
            self::tool('list_channels', 'List connected channel accounts (telegram, instagram, tiktok, whatsapp).', [
                'channel' => ['type' => 'string', 'enum' => ['whatsapp', 'telegram', 'instagram', 'facebook', 'tiktok', 'email']],
            ]),
            self::tool('connect_telegram_bot', 'Connect a Telegram bot with its BotFather token.', [
                'token' => ['type' => 'string'],
            ], ['token']),
            self::tool('connect_instagram_account', 'Connect an Instagram professional account with its access token.', [
                'token' => ['type' => 'string'],
            ], ['token']),
            self::tool('connect_facebook_page', 'Connect a Facebook Page (Messenger) with its Page access token.', [
                'token' => ['type' => 'string'],
            ], ['token']),
            self::tool('connect_email_account', 'Connect a mailbox: SMTP credentials send (and answer with the AI), IMAP (optional) receives into the chat.', [
                'email' => ['type' => 'string'], 'from_name' => ['type' => 'string'],
                'smtp_host' => ['type' => 'string'], 'smtp_port' => ['type' => 'integer'], 'smtp_encryption' => ['type' => 'string', 'enum' => ['ssl', 'tls', 'none']],
                'smtp_username' => ['type' => 'string'], 'smtp_password' => ['type' => 'string'],
                'imap_host' => ['type' => 'string'], 'imap_port' => ['type' => 'integer'], 'imap_encryption' => ['type' => 'string', 'enum' => ['ssl', 'tls', 'none']],
                'imap_username' => ['type' => 'string'], 'imap_password' => ['type' => 'string'],
            ], ['email', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password']),
            self::tool('get_tiktok_connect_url', 'URL the person must open in a browser to authorise a TikTok account.'),
            self::tool('renew_channel_connection', 'Renew tokens / webhook of a Telegram, Instagram, Facebook, TikTok or e-mail account.', [
                'instance_id' => ['type' => 'integer'],
            ], ['instance_id']),
            self::tool('remove_channel_account', 'Disconnect and remove a Telegram, Instagram, Facebook, TikTok or e-mail account.', [
                'instance_id' => ['type' => 'integer'],
            ], ['instance_id']),
            self::tool('get_channel_stats', 'Per-channel numbers (chats, messages, AI replies, first response time) for the last 7, 30 or 90 days.', [
                'days' => ['type' => 'integer', 'enum' => [7, 30, 90]],
            ]),

            // Omnichannel inbox
            self::tool('list_conversations', 'List conversations of every channel, newest first. window_open tells whether we can still reply (Instagram/Facebook 24h, TikTok 48h).', [
                'channel' => ['type' => 'string', 'enum' => ['whatsapp', 'telegram', 'instagram', 'facebook', 'tiktok', 'email']],
                'instance_id' => ['type' => 'integer'],
                'unread' => ['type' => 'boolean', 'description' => 'Only conversations with unread messages'],
                'search' => ['type' => 'string'],
                'page' => ['type' => 'integer'],
                'per_page' => ['type' => 'integer'],
            ]),
            self::tool('get_conversation_messages', 'Messages of a conversation (latest page; pass before_id for older ones).', [
                'chat_id' => ['type' => 'integer'],
                'before_id' => ['type' => 'integer'],
            ], ['chat_id']),
            self::tool('send_conversation_message', 'Send a text message in a conversation, through the channel it belongs to.', [
                'chat_id' => ['type' => 'integer'],
                'text' => ['type' => 'string'],
            ], ['chat_id', 'text']),
            self::tool('mark_conversation_read', 'Mark a conversation as read.', [
                'chat_id' => ['type' => 'integer'],
            ], ['chat_id']),
            self::tool('pause_conversation_ai', 'Pause (true) or resume (false) the AI agent in a conversation.', [
                'chat_id' => ['type' => 'integer'],
                'paused' => ['type' => 'boolean'],
            ], ['chat_id', 'paused']),

            // Approval, audience and team
            self::tool('preview_campaign_audience', 'How many contacts a campaign message would reach, by score (tiers / min_score / max_score) and channels. save=true stores it on the message.', [
                'campaign_item_id' => ['type' => 'integer'],
                'tiers' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['hot', 'warm', 'cold', 'frozen']]],
                'min_score' => ['type' => 'integer'],
                'max_score' => ['type' => 'integer'],
                'channels' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['whatsapp', 'telegram', 'instagram', 'facebook', 'tiktok']]],
                'save' => ['type' => 'boolean'],
            ], ['campaign_item_id']),
            self::tool('submit_campaign_item_for_approval', 'Send a campaign message to the approvers (optionally suggesting the audience).', [
                'campaign_item_id' => ['type' => 'integer'],
                'tiers' => ['type' => 'array', 'items' => ['type' => 'string']],
                'channels' => ['type' => 'array', 'items' => ['type' => 'string']],
            ], ['campaign_item_id']),
            self::tool('list_pending_approvals', 'Campaign messages waiting for approval.'),
            self::tool('approve_campaign_item', 'Approve a campaign message with its audience (score + channels). Without audience fields the saved audience is used.', [
                'campaign_item_id' => ['type' => 'integer'],
                'tiers' => ['type' => 'array', 'items' => ['type' => 'string']],
                'min_score' => ['type' => 'integer'],
                'max_score' => ['type' => 'integer'],
                'channels' => ['type' => 'array', 'items' => ['type' => 'string']],
                'note' => ['type' => 'string'],
            ], ['campaign_item_id']),
            self::tool('reject_campaign_item', 'Reject a campaign message.', [
                'campaign_item_id' => ['type' => 'integer'],
                'note' => ['type' => 'string'],
            ], ['campaign_item_id']),
            self::tool('list_team_members', 'Sub-users of the account and the available roles (admin, approver, creator, sender, agent, analyst).'),
            self::tool('add_team_member', 'Add a sub-user with a role.', [
                'name' => ['type' => 'string'], 'email' => ['type' => 'string'], 'password' => ['type' => 'string'],
                'role' => ['type' => 'string', 'enum' => ['admin', 'approver', 'creator', 'sender', 'agent', 'analyst']],
            ], ['name', 'email', 'password', 'role']),
            self::tool('update_team_member', 'Change a sub-user (role, active, name, password).', [
                'member_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'password' => ['type' => 'string'],
                'role' => ['type' => 'string', 'enum' => ['admin', 'approver', 'creator', 'sender', 'agent', 'analyst']], 'active' => ['type' => 'boolean'],
            ], ['member_id']),
            self::tool('remove_team_member', 'Remove a sub-user.', [
                'member_id' => ['type' => 'integer'],
            ], ['member_id']),

            // Campaigns
            self::tool('list_campaigns', 'List campaigns.', [
                'page' => ['type' => 'integer'],
                'per_page' => ['type' => 'integer'],
            ]),
            self::tool('get_campaign', 'Get campaign details.', [
                'campaign_id' => ['type' => 'integer'],
            ], ['campaign_id']),
            self::tool('create_campaign', 'Create a campaign.', [
                'name' => ['type' => 'string', 'description' => 'Campaign name'],
            ], ['name']),
            self::tool('update_campaign', 'Update a campaign.', [
                'campaign_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
            ], ['campaign_id', 'name']),
            self::tool('delete_campaign', 'Delete a campaign and its items.', [
                'campaign_id' => ['type' => 'integer'],
            ], ['campaign_id']),
            self::tool('get_campaign_report', 'Get campaign delivery report with stats.', [
                'campaign_id' => ['type' => 'integer'],
            ], ['campaign_id']),

            // Campaign Items
            self::tool('list_campaign_items', 'List campaign message items.', [
                'campaign_id' => ['type' => 'integer', 'description' => 'Filter by campaign'],
                'page' => ['type' => 'integer'],
                'per_page' => ['type' => 'integer'],
            ]),
            self::tool('get_campaign_item', 'Get campaign item details.', [
                'campaign_item_id' => ['type' => 'integer'],
            ], ['campaign_item_id']),
            self::tool('create_campaign_item', 'Create a campaign message item.', [
                'campaign_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'text' => ['type' => 'string', 'description' => 'Message text or caption'],
                'image_url' => ['type' => 'string', 'description' => 'Optional media URL'],
                'welcome_enabled' => ['type' => 'boolean'],
            ], ['campaign_id', 'name', 'text']),
            self::tool('update_campaign_item', 'Update a campaign item.', [
                'campaign_item_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'text' => ['type' => 'string'],
                'image_url' => ['type' => 'string'],
                'welcome_enabled' => ['type' => 'boolean'],
            ], ['campaign_item_id']),
            self::tool('delete_campaign_item', 'Delete a campaign item.', [
                'campaign_item_id' => ['type' => 'integer'],
            ], ['campaign_item_id']),
            self::tool('generate_test_send', 'Create a test send job for one contact.', [
                'campaign_item_id' => ['type' => 'integer'],
            ], ['campaign_item_id']),
            self::tool('launch_campaign', 'Generate send jobs for all validated contacts and queue campaign.', [
                'campaign_item_id' => ['type' => 'integer'],
            ], ['campaign_item_id']),

            // WhatsApp Jobs
            self::tool('list_send_jobs', 'List send jobs for a campaign item with filters.', [
                'campaign_item_id' => ['type' => 'integer'],
                'contact' => ['type' => 'string', 'description' => 'Filter by contact name or phone'],
                'status' => ['type' => 'string', 'description' => 'Job status filter'],
                'evolution_status' => ['type' => 'string'],
                'page' => ['type' => 'integer'],
                'per_page' => ['type' => 'integer'],
            ], ['campaign_item_id']),
            self::tool('retry_send_job', 'Retry a failed send job.', [
                'job_id' => ['type' => 'integer'],
            ], ['job_id']),
            self::tool('bulk_retry_send_jobs', 'Retry multiple failed send jobs.', [
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ], ['ids']),
            self::tool('bulk_delete_send_jobs', 'Delete multiple send jobs.', [
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ], ['ids']),

            // Instances
            self::tool('list_instances', 'List WhatsApp instances.', [
                'page' => ['type' => 'integer'],
                'per_page' => ['type' => 'integer'],
            ]),
            self::tool('get_instance', 'Get instance details and connection status.', [
                'instance_id' => ['type' => 'integer'],
            ], ['instance_id']),
            self::tool('create_instance', 'Register a new WhatsApp instance.', [
                'name' => ['type' => 'string', 'description' => 'Friendly name'],
                'phone' => ['type' => 'string', 'description' => 'Phone number'],
            ], ['name', 'phone']),
            self::tool('delete_instance', 'Delete a WhatsApp instance.', [
                'instance_id' => ['type' => 'integer'],
            ], ['instance_id']),
            self::tool('get_instance_qr', 'Get QR code to connect WhatsApp instance.', [
                'instance_id' => ['type' => 'integer'],
            ], ['instance_id']),
            self::tool('check_instance_connection', 'Check if instance is connected to WhatsApp.', [
                'instance_id' => ['type' => 'integer'],
            ], ['instance_id']),
            self::tool('toggle_instance_warmup', 'Enable or disable warmup for an instance.', [
                'instance_id' => ['type' => 'integer'],
            ], ['instance_id']),
            self::tool('sync_instance_contacts', 'Sync contacts from WhatsApp to database.', [
                'instance_id' => ['type' => 'integer'],
            ], ['instance_id']),

            // Notifications
            self::tool('list_notifications', 'List user notifications.', [
                'page' => ['type' => 'integer'],
                'per_page' => ['type' => 'integer'],
            ]),
            self::tool('mark_notifications_read', 'Mark all notifications as read.'),
        ];

        if ($isAdmin) {
            $tools = array_merge($tools, self::adminTools());
        }

        return $tools;
    }

    /**
     * Map tool name to controller action.
     */
    public static function handlers(): array
    {
        return [
            'get_dashboard' => [\App\Http\Controllers\Api\DashboardController::class, 'index'],
            'get_profile' => [\App\Http\Controllers\Api\ProfileController::class, 'show'],
            'update_profile' => [\App\Http\Controllers\Api\ProfileController::class, 'update'],

            'list_contacts' => [\App\Http\Controllers\Api\ContactController::class, 'index'],
            'get_contact' => [\App\Http\Controllers\Api\ContactController::class, 'show', 'contact_id' => 'contact'],
            'create_contact' => [\App\Http\Controllers\Api\ContactController::class, 'store'],
            'update_contact' => [\App\Http\Controllers\Api\ContactController::class, 'update', 'contact_id' => 'contact'],
            'delete_contact' => [\App\Http\Controllers\Api\ContactController::class, 'destroy', 'contact_id' => 'contact'],
            'bulk_delete_contacts' => [\App\Http\Controllers\Api\ContactController::class, 'bulkDelete'],
            'bulk_update_contact_status' => [\App\Http\Controllers\Api\ContactController::class, 'bulkStatus'],
            'clear_contacts' => [\App\Http\Controllers\Api\ContactController::class, 'clean'],
            'sync_contact_photo' => [\App\Http\Controllers\Api\ContactController::class, 'syncPhoto', 'contact_id' => 'contact'],

            'get_contact_ranking' => [\App\Http\Controllers\Api\ContactController::class, 'ranking'],
            'rescore_contact' => [\App\Http\Controllers\Api\ContactController::class, 'rescore', 'contact_id' => 'contact'],
            'get_contact_channels' => [\App\Http\Controllers\Api\ContactController::class, 'channels', 'contact_id' => 'contact'],
            'link_contacts' => [\App\Http\Controllers\Api\ContactController::class, 'link', 'contact_id' => 'contact'],
            'unlink_contact' => [\App\Http\Controllers\Api\ContactController::class, 'unlink', 'contact_id' => 'contact'],
            'check_unresponsive_contacts' => [\App\Http\Controllers\Api\ContactController::class, 'checkUnresponsive'],

            'list_channels' => [\App\Http\Controllers\Api\ChannelController::class, 'index'],
            'connect_telegram_bot' => [\App\Http\Controllers\Api\ChannelController::class, 'connectTelegram'],
            'connect_instagram_account' => [\App\Http\Controllers\Api\ChannelController::class, 'connectInstagram'],
            'connect_facebook_page' => [\App\Http\Controllers\Api\ChannelController::class, 'connectFacebook'],
            'connect_email_account' => [\App\Http\Controllers\Api\ChannelController::class, 'connectEmail'],
            'get_tiktok_connect_url' => [\App\Http\Controllers\Api\ChannelController::class, 'tiktokUrl'],
            'renew_channel_connection' => [\App\Http\Controllers\Api\ChannelController::class, 'renew', 'instance_id' => 'instance'],
            'remove_channel_account' => [\App\Http\Controllers\Api\ChannelController::class, 'destroy', 'instance_id' => 'instance'],
            'get_channel_stats' => [\App\Http\Controllers\Api\ChannelController::class, 'stats'],

            'list_conversations' => [\App\Http\Controllers\Api\ConversationController::class, 'index'],
            'get_conversation_messages' => [\App\Http\Controllers\Api\ConversationController::class, 'messages', 'chat_id' => 'chat'],
            'send_conversation_message' => [\App\Http\Controllers\Api\ConversationController::class, 'send', 'chat_id' => 'chat'],
            'mark_conversation_read' => [\App\Http\Controllers\Api\ConversationController::class, 'markRead', 'chat_id' => 'chat'],
            'pause_conversation_ai' => [\App\Http\Controllers\Api\ConversationController::class, 'aiPause', 'chat_id' => 'chat'],

            'preview_campaign_audience' => [\App\Http\Controllers\Api\ApprovalController::class, 'audience', 'campaign_item_id' => 'campaignItem'],
            'submit_campaign_item_for_approval' => [\App\Http\Controllers\Api\ApprovalController::class, 'submit', 'campaign_item_id' => 'campaignItem'],
            'list_pending_approvals' => [\App\Http\Controllers\Api\ApprovalController::class, 'pending'],
            'approve_campaign_item' => [\App\Http\Controllers\Api\ApprovalController::class, 'approve', 'campaign_item_id' => 'campaignItem'],
            'reject_campaign_item' => [\App\Http\Controllers\Api\ApprovalController::class, 'reject', 'campaign_item_id' => 'campaignItem'],
            'list_team_members' => [\App\Http\Controllers\Api\TeamController::class, 'index'],
            'add_team_member' => [\App\Http\Controllers\Api\TeamController::class, 'store'],
            'update_team_member' => [\App\Http\Controllers\Api\TeamController::class, 'update', 'member_id' => 'member'],
            'remove_team_member' => [\App\Http\Controllers\Api\TeamController::class, 'destroy', 'member_id' => 'member'],

            'list_campaigns' => [\App\Http\Controllers\Api\CampaignController::class, 'index'],
            'get_campaign' => [\App\Http\Controllers\Api\CampaignController::class, 'show', 'campaign_id' => 'campaign'],
            'create_campaign' => [\App\Http\Controllers\Api\CampaignController::class, 'store'],
            'update_campaign' => [\App\Http\Controllers\Api\CampaignController::class, 'update', 'campaign_id' => 'campaign'],
            'delete_campaign' => [\App\Http\Controllers\Api\CampaignController::class, 'destroy', 'campaign_id' => 'campaign'],
            'get_campaign_report' => [\App\Http\Controllers\Api\CampaignController::class, 'report', 'campaign_id' => 'campaign'],

            'list_campaign_items' => [\App\Http\Controllers\Api\CampaignItemController::class, 'index'],
            'get_campaign_item' => [\App\Http\Controllers\Api\CampaignItemController::class, 'show', 'campaign_item_id' => 'campaignItem'],
            'create_campaign_item' => [\App\Http\Controllers\Api\CampaignItemController::class, 'store'],
            'update_campaign_item' => [\App\Http\Controllers\Api\CampaignItemController::class, 'update', 'campaign_item_id' => 'campaignItem'],
            'delete_campaign_item' => [\App\Http\Controllers\Api\CampaignItemController::class, 'destroy', 'campaign_item_id' => 'campaignItem'],
            'generate_test_send' => [\App\Http\Controllers\Api\CampaignItemController::class, 'generateTest', 'campaign_item_id' => 'campaignItem'],
            'launch_campaign' => [\App\Http\Controllers\Api\CampaignItemController::class, 'generateAll', 'campaign_item_id' => 'campaignItem'],

            'list_send_jobs' => [\App\Http\Controllers\Api\WhatsappJobController::class, 'index', 'campaign_item_id' => 'campaignItemId'],
            'retry_send_job' => [\App\Http\Controllers\Api\WhatsappJobController::class, 'retry', 'job_id' => 'id'],
            'bulk_retry_send_jobs' => [\App\Http\Controllers\Api\WhatsappJobController::class, 'bulkRetry'],
            'bulk_delete_send_jobs' => [\App\Http\Controllers\Api\WhatsappJobController::class, 'bulkDelete'],

            'list_instances' => [\App\Http\Controllers\Api\InstanceController::class, 'index'],
            'get_instance' => [\App\Http\Controllers\Api\InstanceController::class, 'show', 'instance_id' => 'instance'],
            'create_instance' => [\App\Http\Controllers\Api\InstanceController::class, 'store'],
            'delete_instance' => [\App\Http\Controllers\Api\InstanceController::class, 'destroy', 'instance_id' => 'instance'],
            'get_instance_qr' => [\App\Http\Controllers\Api\InstanceController::class, 'qr', 'instance_id' => 'instance'],
            'check_instance_connection' => [\App\Http\Controllers\Api\InstanceController::class, 'connection', 'instance_id' => 'instance'],
            'toggle_instance_warmup' => [\App\Http\Controllers\Api\InstanceController::class, 'toggleWarmup', 'instance_id' => 'instance'],
            'sync_instance_contacts' => [\App\Http\Controllers\Api\InstanceController::class, 'sync', 'instance_id' => 'instance'],

            'list_notifications' => [\App\Http\Controllers\Api\NotificationController::class, 'index'],
            'mark_notifications_read' => [\App\Http\Controllers\Api\NotificationController::class, 'markRead'],

            // Admin
            'admin_list_users' => [\App\Http\Controllers\Api\Admin\UserController::class, 'index'],
            'admin_get_user' => [\App\Http\Controllers\Api\Admin\UserController::class, 'show', 'user_id' => 'user'],
            'admin_update_user' => [\App\Http\Controllers\Api\Admin\UserController::class, 'update', 'user_id' => 'user'],
            'admin_delete_user' => [\App\Http\Controllers\Api\Admin\UserController::class, 'destroy', 'user_id' => 'user'],
            'admin_toggle_user_active' => [\App\Http\Controllers\Api\Admin\UserController::class, 'toggleActive', 'user_id' => 'user'],
            'admin_toggle_user_admin' => [\App\Http\Controllers\Api\Admin\UserController::class, 'toggleAdmin', 'user_id' => 'user'],
            'admin_panic_status' => [\App\Http\Controllers\Api\Admin\PanicController::class, 'status'],
            'admin_toggle_panic' => [\App\Http\Controllers\Api\Admin\PanicController::class, 'toggle'],
            'admin_clear_send_queue' => [\App\Http\Controllers\Api\Admin\PanicController::class, 'clearQueue'],
            'admin_clear_warmup_queue' => [\App\Http\Controllers\Api\Admin\PanicController::class, 'clearWarmupQueue'],
        ];
    }

    private static function adminTools(): array
    {
        return [
            self::tool('admin_list_users', 'List all users (admin only).', [
                'search' => ['type' => 'string'],
                'page' => ['type' => 'integer'],
                'per_page' => ['type' => 'integer'],
            ]),
            self::tool('admin_get_user', 'Get user details (admin only).', [
                'user_id' => ['type' => 'integer'],
            ], ['user_id']),
            self::tool('admin_update_user', 'Update a user (admin only).', [
                'user_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'username' => ['type' => 'string'],
                'password' => ['type' => 'string'],
                'password_confirmation' => ['type' => 'string'],
            ], ['user_id']),
            self::tool('admin_delete_user', 'Delete a user (admin only).', [
                'user_id' => ['type' => 'integer'],
            ], ['user_id']),
            self::tool('admin_toggle_user_active', 'Toggle user active status (admin only).', [
                'user_id' => ['type' => 'integer'],
            ], ['user_id']),
            self::tool('admin_toggle_user_admin', 'Toggle user admin role (admin only).', [
                'user_id' => ['type' => 'integer'],
            ], ['user_id']),
            self::tool('admin_panic_status', 'Get system panic mode status (admin only).'),
            self::tool('admin_toggle_panic', 'Toggle system panic mode - pause/resume sending (admin only).'),
            self::tool('admin_clear_send_queue', 'Clear the send queue (admin only).'),
            self::tool('admin_clear_warmup_queue', 'Clear the warmup queue (admin only).'),
        ];
    }

    private static function tool(string $name, string $description, array $properties = [], array $required = []): array
    {
        $parameters = [
            'type' => 'object',
            'properties' => (object) $properties,
        ];

        if (!empty($required)) {
            $parameters['required'] = $required;
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $parameters,
            ],
        ];
    }
}

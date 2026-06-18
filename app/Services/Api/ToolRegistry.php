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

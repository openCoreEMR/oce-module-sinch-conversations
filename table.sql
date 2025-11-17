-- This table definition is loaded and then executed when the OpenEMR interface's install button is clicked.

-- Table to store patient conversations
CREATE TABLE IF NOT EXISTS `oce_sinch_conversations` (
  `id` INT(11) PRIMARY KEY AUTO_INCREMENT NOT NULL,
  `conversation_id` VARCHAR(255) UNIQUE NOT NULL COMMENT 'Sinch conversation ID',
  `contact_id` VARCHAR(255) NOT NULL COMMENT 'Sinch contact ID',
  `patient_id` BIGINT(20) DEFAULT NULL COMMENT 'Associated patient ID',
  `provider_id` BIGINT(20) DEFAULT NULL COMMENT 'Assigned provider ID',
  `channel` VARCHAR(50) DEFAULT 'SMS' COMMENT 'Primary channel (SMS, WHATSAPP, RCS, etc)',
  `status` VARCHAR(50) DEFAULT 'ACTIVE' COMMENT 'Conversation status',
  `last_polled_at` DATETIME DEFAULT NULL COMMENT 'Last time we polled for new messages',
  `last_message_at` DATETIME DEFAULT NULL COMMENT 'Last message sent or received',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When this conversation was created',
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'When this record was last updated',
  INDEX `idx_conversation_id` (`conversation_id`),
  INDEX `idx_contact_id` (`contact_id`),
  INDEX `idx_patient_id` (`patient_id`),
  INDEX `idx_provider_id` (`provider_id`),
  INDEX `idx_last_polled` (`last_polled_at`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to store individual messages
CREATE TABLE IF NOT EXISTS `oce_sinch_messages` (
  `id` INT(11) PRIMARY KEY AUTO_INCREMENT NOT NULL,
  `conversation_id` VARCHAR(255) NOT NULL COMMENT 'Sinch conversation ID',
  `message_id` VARCHAR(255) UNIQUE NOT NULL COMMENT 'Sinch message ID',
  `direction` ENUM('inbound', 'outbound') NOT NULL COMMENT 'Direction of the message',
  `channel` VARCHAR(50) NOT NULL COMMENT 'Channel used (SMS, WHATSAPP, etc)',
  `from_identifier` VARCHAR(255) DEFAULT NULL COMMENT 'Sender identifier (phone, WhatsApp ID, etc)',
  `to_identifier` VARCHAR(255) DEFAULT NULL COMMENT 'Recipient identifier',
  `body` TEXT DEFAULT NULL COMMENT 'Message body text',
  `media_url` TEXT DEFAULT NULL COMMENT 'URL to media attachments (MMS)',
  `status` VARCHAR(50) DEFAULT 'SENT' COMMENT 'Message status (QUEUED, SENT, DELIVERED, READ, FAILED)',
  `error_details` TEXT DEFAULT NULL COMMENT 'Error details if failed',
  `template_key` VARCHAR(100) DEFAULT NULL COMMENT 'Template used for this message',
  `metadata` JSON DEFAULT NULL COMMENT 'Additional metadata',
  `sent_at` DATETIME DEFAULT NULL COMMENT 'When message was sent',
  `delivered_at` DATETIME DEFAULT NULL COMMENT 'When message was delivered',
  `read_at` DATETIME DEFAULT NULL COMMENT 'When message was read',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When this record was created',
  INDEX `idx_conversation_id` (`conversation_id`),
  INDEX `idx_message_id` (`message_id`),
  INDEX `idx_direction` (`direction`),
  INDEX `idx_status` (`status`),
  INDEX `idx_template_key` (`template_key`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to store patient contact information and channel preferences
CREATE TABLE IF NOT EXISTS `oce_sinch_contacts` (
  `id` INT(11) PRIMARY KEY AUTO_INCREMENT NOT NULL,
  `patient_id` BIGINT(20) NOT NULL COMMENT 'Patient ID',
  `contact_id` VARCHAR(255) UNIQUE NOT NULL COMMENT 'Sinch contact ID',
  `channel` VARCHAR(50) NOT NULL COMMENT 'Channel type (SMS, WHATSAPP, etc)',
  `channel_identity` VARCHAR(255) NOT NULL COMMENT 'Channel-specific identifier (phone number, WhatsApp ID, etc)',
  `display_name` VARCHAR(255) DEFAULT NULL COMMENT 'Display name for this contact',
  `opted_in` BOOLEAN DEFAULT FALSE COMMENT 'Whether patient has opted in to messages',
  `opted_out` BOOLEAN DEFAULT FALSE COMMENT 'Whether patient has opted out',
  `preferences` JSON DEFAULT NULL COMMENT 'Channel and notification preferences',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When this contact was created',
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'When this record was last updated',
  INDEX `idx_patient_id` (`patient_id`),
  INDEX `idx_contact_id` (`contact_id`),
  INDEX `idx_channel_identity` (`channel_identity`),
  INDEX `idx_opted_in` (`opted_in`),
  UNIQUE KEY `unique_patient_channel` (`patient_id`, `channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to store patient consent records
CREATE TABLE IF NOT EXISTS `oce_sinch_patient_consent` (
  `id` INT(11) PRIMARY KEY AUTO_INCREMENT NOT NULL,
  `patient_id` BIGINT(20) NOT NULL COMMENT 'Patient ID',
  `phone_number` VARCHAR(20) NOT NULL COMMENT 'Phone number in E.164 format',
  `opted_in` BOOLEAN DEFAULT FALSE COMMENT 'Current opt-in status',
  `opt_in_method` VARCHAR(50) DEFAULT NULL COMMENT 'How they opted in (web_form, portal, in_person, etc)',
  `opt_in_date` DATETIME DEFAULT NULL COMMENT 'When they opted in',
  `opt_in_ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'IP address when opted in',
  `opted_out` BOOLEAN DEFAULT FALSE COMMENT 'Current opt-out status',
  `opt_out_date` DATETIME DEFAULT NULL COMMENT 'When they opted out',
  `opt_out_method` VARCHAR(50) DEFAULT NULL COMMENT 'How they opted out (sms_stop, web_form, etc)',
  `consent_text` TEXT DEFAULT NULL COMMENT 'Full text of consent agreement',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When this record was created',
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'When this record was last updated',
  INDEX `idx_patient_id` (`patient_id`),
  INDEX `idx_phone_number` (`phone_number`),
  INDEX `idx_opted_in` (`opted_in`),
  INDEX `idx_opted_out` (`opted_out`),
  UNIQUE KEY `unique_patient_phone` (`patient_id`, `phone_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to store message templates
CREATE TABLE IF NOT EXISTS `oce_sinch_message_templates` (
  `id` INT(11) PRIMARY KEY AUTO_INCREMENT NOT NULL,
  `template_key` VARCHAR(100) UNIQUE NOT NULL COMMENT 'Unique key (appointment_reminder, opt_in_confirmation, etc)',
  `template_name` VARCHAR(255) NOT NULL COMMENT 'Human-readable name',
  `category` VARCHAR(50) NOT NULL COMMENT 'Category (consent, appointments, portal, billing, wellness, operations)',
  `communication_type` ENUM('individual', 'batch', 'both') NOT NULL COMMENT 'Can be used for individual, batch, or both',
  `body` TEXT NOT NULL COMMENT 'Template body with {{ variables }}',
  `required_variables` JSON NOT NULL COMMENT 'Array of required variable names',
  `compliance_confidence` INT NOT NULL DEFAULT 95 COMMENT 'Compliance confidence score (90-100)',
  `sinch_approved` BOOLEAN DEFAULT TRUE COMMENT 'Whether approved by Sinch compliance',
  `active` BOOLEAN DEFAULT TRUE COMMENT 'Whether template is active',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When this template was created',
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'When this record was last updated',
  INDEX `idx_template_key` (`template_key`),
  INDEX `idx_category` (`category`),
  INDEX `idx_communication_type` (`communication_type`),
  INDEX `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to store keyword auto-responses (STOP, HELP, etc)
CREATE TABLE IF NOT EXISTS `oce_sinch_keyword_responses` (
  `id` INT(11) PRIMARY KEY AUTO_INCREMENT NOT NULL,
  `keyword` VARCHAR(20) NOT NULL COMMENT 'Keyword (STOP, START, HELP, etc)',
  `response_template` TEXT NOT NULL COMMENT 'Response message template',
  `active` BOOLEAN DEFAULT TRUE COMMENT 'Whether this response is active',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When this response was created',
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'When this record was last updated',
  UNIQUE INDEX `idx_keyword` (`keyword`),
  INDEX `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default keyword responses
INSERT INTO `oce_sinch_keyword_responses` (`keyword`, `response_template`, `active`) VALUES
('STOP', '{{ clinic_name }}: You have been unsubscribed from our text notifications. You will not receive further messages. Reply START to re-subscribe or call {{ phone }} for assistance.', TRUE),
('START', '{{ clinic_name }}: You have been re-subscribed to text notifications. Reply HELP for help. Reply STOP to unsubscribe.', TRUE),
('UNSTOP', '{{ clinic_name }}: You have been re-subscribed to text notifications. Reply HELP for help. Reply STOP to unsubscribe.', TRUE),
('HELP', '{{ clinic_name }}: Text notifications from {{ clinic_name }}. For assistance, call {{ phone }}. Msg&Data rates may apply. Reply STOP to unsubscribe.', TRUE);

-- Table to store Sinch service configuration
CREATE TABLE IF NOT EXISTS `oce_sinch_services` (
    `id` INT(11) PRIMARY KEY AUTO_INCREMENT NOT NULL,
    `service_name` VARCHAR(100) NOT NULL COMMENT 'Friendly name for this service',
    `project_id` VARCHAR(255) NOT NULL COMMENT 'Sinch project ID',
    `app_id` VARCHAR(255) DEFAULT NULL COMMENT 'Sinch app ID (for Conversations API)',
    `api_key` VARCHAR(255) DEFAULT NULL COMMENT 'Sinch API key (encrypted)',
    `api_secret` TEXT DEFAULT NULL COMMENT 'Sinch API secret (encrypted)',
    `oauth_token` TEXT DEFAULT NULL COMMENT 'OAuth token if using OAuth2 authentication',
    `oauth_token_expires` DATETIME DEFAULT NULL COMMENT 'When the OAuth token expires',
    `region` VARCHAR(50) DEFAULT 'us' COMMENT 'Sinch API region (us, eu, etc)',
    `default_channel` VARCHAR(50) DEFAULT 'SMS' COMMENT 'Default channel for messages',
    `clinic_name` VARCHAR(255) DEFAULT NULL COMMENT 'Clinic name for message templates',
    `clinic_phone` VARCHAR(20) DEFAULT NULL COMMENT 'Clinic phone number for templates',
    `is_active` BOOLEAN DEFAULT TRUE COMMENT 'Whether this service is active',
    `is_default` BOOLEAN DEFAULT FALSE COMMENT 'Whether this is the default service',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When this record was created',
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'When this record was last updated',
    UNIQUE INDEX `idx_service_name` (`service_name`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_is_default` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

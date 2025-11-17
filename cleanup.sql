-- This file is executed when the module is uninstalled via the OpenEMR interface

-- Drop tables in reverse dependency order
DROP TABLE IF EXISTS `oce_sinch_messages`;
DROP TABLE IF EXISTS `oce_sinch_conversations`;
DROP TABLE IF EXISTS `oce_sinch_contacts`;
DROP TABLE IF EXISTS `oce_sinch_patient_consent`;
DROP TABLE IF EXISTS `oce_sinch_message_templates`;
DROP TABLE IF EXISTS `oce_sinch_keyword_responses`;
DROP TABLE IF EXISTS `oce_sinch_services`;

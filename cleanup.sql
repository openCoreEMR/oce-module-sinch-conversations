-- Manual cleanup script for the OpenCoreEMR Sinch Conversations Module
--
-- IMPORTANT: OpenEMR does not automatically execute this file during uninstall.
-- You must run it manually if you need to reinstall the module cleanly.
--
-- Usage:
--   docker compose exec -T mysql mariadb -uroot -proot openemr < cleanup.sql
--   OR via phpMyAdmin / MySQL client
--
-- Drop tables in reverse dependency order
DROP TABLE IF EXISTS `oce_sinch_messages`;
DROP TABLE IF EXISTS `oce_sinch_conversations`;
DROP TABLE IF EXISTS `oce_sinch_contacts`;
DROP TABLE IF EXISTS `oce_sinch_patient_consent`;
DROP TABLE IF EXISTS `oce_sinch_message_templates`;
DROP TABLE IF EXISTS `oce_sinch_keyword_responses`;
DROP TABLE IF EXISTS `oce_sinch_services`;

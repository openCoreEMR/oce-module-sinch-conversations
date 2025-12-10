<?php

/**
 * Message Templates for Sinch Conversations
 *
 * These templates are synced to Sinch via the Template Management API.
 * They follow HIPAA/TCPA compliance guidelines.
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

return [
    // Consent & Opt-in Templates
    [
        'template_key' => 'opt_in_confirmation',
        'template_name' => 'Opt-in Confirmation',
        'category' => 'consent',
        'communication_type' => 'individual',
        'description' => 'Confirms patient has opted in to text notifications',
        'body' => 'Welcome to {{ clinic_name }} text notifications! You will receive appointment reminders and important updates. Reply STOP to opt out or HELP for assistance. Msg&data rates may apply.',
        'required_variables' => ['clinic_name'],
        'compliance_confidence' => 100,
    ],

    [
        'template_key' => 'opt_out_confirmation',
        'template_name' => 'Opt-out Confirmation',
        'category' => 'consent',
        'communication_type' => 'individual',
        'description' => 'Confirms patient has opted out',
        'body' => '{{ clinic_name }}: You have been unsubscribed from text notifications. You will not receive further messages. Call {{ clinic_phone }} if you need assistance.',
        'required_variables' => ['clinic_name', 'clinic_phone'],
        'compliance_confidence' => 100,
    ],

    // Appointment Templates
    [
        'template_key' => 'appointment_reminder',
        'template_name' => 'Appointment Reminder',
        'category' => 'appointments',
        'communication_type' => 'batch',
        'description' => 'Reminds patient of upcoming appointment',
        'body' => 'Hi {{ patient_name }}, this is {{ clinic_name }}. Reminder: You have an appointment on {{ appointment_date }} at {{ appointment_time }}. Reply C to confirm or call {{ clinic_phone }}.',
        'required_variables' => ['patient_name', 'clinic_name', 'appointment_date', 'appointment_time', 'clinic_phone'],
        'compliance_confidence' => 98,
    ],

    [
        'template_key' => 'appointment_confirmation',
        'template_name' => 'Appointment Confirmation',
        'category' => 'appointments',
        'communication_type' => 'individual',
        'description' => 'Confirms appointment has been scheduled',
        'body' => 'Your appointment with {{ clinic_name }} is confirmed for {{ appointment_date }} at {{ appointment_time }}. Location: {{ clinic_address }}. Call {{ clinic_phone }} to reschedule.',
        'required_variables' => ['clinic_name', 'appointment_date', 'appointment_time', 'clinic_address', 'clinic_phone'],
        'compliance_confidence' => 98,
    ],

    [
        'template_key' => 'appointment_cancellation',
        'template_name' => 'Appointment Cancellation',
        'category' => 'appointments',
        'communication_type' => 'individual',
        'description' => 'Notifies patient of cancelled appointment',
        'body' => '{{ clinic_name }}: Your appointment scheduled for {{ appointment_date }} at {{ appointment_time }} has been cancelled. Please call {{ clinic_phone }} to reschedule.',
        'required_variables' => ['clinic_name', 'appointment_date', 'appointment_time', 'clinic_phone'],
        'compliance_confidence' => 98,
    ],

    // Portal Templates
    [
        'template_key' => 'portal_message_notification',
        'template_name' => 'Portal Message Notification',
        'category' => 'portal',
        'communication_type' => 'individual',
        'description' => 'Notifies patient of new portal message',
        'body' => '{{ clinic_name }}: You have a new message in your patient portal. Log in at {{ portal_url }} to view. Reply STOP to opt out of notifications.',
        'required_variables' => ['clinic_name', 'portal_url'],
        'compliance_confidence' => 95,
    ],

    [
        'template_key' => 'test_results_available',
        'template_name' => 'Test Results Available',
        'category' => 'portal',
        'communication_type' => 'individual',
        'description' => 'Notifies patient test results are ready',
        'body' => '{{ clinic_name }}: Your test results are now available in your patient portal. Log in at {{ portal_url }} to view. Call {{ clinic_phone }} with questions.',
        'required_variables' => ['clinic_name', 'portal_url', 'clinic_phone'],
        'compliance_confidence' => 95,
    ],

    // Billing Templates
    [
        'template_key' => 'payment_reminder',
        'template_name' => 'Payment Reminder',
        'category' => 'billing',
        'communication_type' => 'batch',
        'description' => 'Reminds patient of outstanding balance',
        'body' => '{{ clinic_name }}: You have an outstanding balance of ${{ amount }}. Pay online at {{ payment_url }} or call {{ clinic_phone }}. Reply STOP to opt out.',
        'required_variables' => ['clinic_name', 'amount', 'payment_url', 'clinic_phone'],
        'compliance_confidence' => 90,
    ],

    [
        'template_key' => 'payment_confirmation',
        'template_name' => 'Payment Confirmation',
        'category' => 'billing',
        'communication_type' => 'individual',
        'description' => 'Confirms payment received',
        'body' => '{{ clinic_name }}: Thank you! We received your payment of ${{ amount }}. Your new balance is ${{ remaining_balance }}. Questions? Call {{ clinic_phone }}.',
        'required_variables' => ['clinic_name', 'amount', 'remaining_balance', 'clinic_phone'],
        'compliance_confidence' => 95,
    ],

    // Wellness Templates
    [
        'template_key' => 'annual_checkup_reminder',
        'template_name' => 'Annual Checkup Reminder',
        'category' => 'wellness',
        'communication_type' => 'batch',
        'description' => 'Reminds patient to schedule annual checkup',
        'body' => 'Hi {{ patient_name }}, {{ clinic_name }} reminds you it\'s time for your annual checkup. Call {{ clinic_phone }} to schedule. Reply STOP to opt out of wellness reminders.',
        'required_variables' => ['patient_name', 'clinic_name', 'clinic_phone'],
        'compliance_confidence' => 95,
    ],

    [
        'template_key' => 'prescription_ready',
        'template_name' => 'Prescription Ready',
        'category' => 'operations',
        'communication_type' => 'individual',
        'description' => 'Notifies patient prescription is ready',
        'body' => '{{ clinic_name }}: Your prescription is ready for pickup at {{ pharmacy_name }}. Hours: {{ pharmacy_hours }}. Call {{ pharmacy_phone }} with questions.',
        'required_variables' => ['clinic_name', 'pharmacy_name', 'pharmacy_hours', 'pharmacy_phone'],
        'compliance_confidence' => 95,
    ],

    // Office Operations
    [
        'template_key' => 'office_closure_notification',
        'template_name' => 'Office Closure Notification',
        'category' => 'operations',
        'communication_type' => 'batch',
        'description' => 'Notifies patients of office closure',
        'body' => '{{ clinic_name }} will be closed on {{ closure_date }} for {{ closure_reason }}. We will reopen {{ reopen_date }}. For emergencies, call {{ emergency_phone }}.',
        'required_variables' => ['clinic_name', 'closure_date', 'closure_reason', 'reopen_date', 'emergency_phone'],
        'compliance_confidence' => 98,
    ],
];

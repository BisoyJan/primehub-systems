<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Role Definitions
    |--------------------------------------------------------------------------
    |
    | Define all the roles available in the system.
    | These should match the enum values in the users table migration.
    |
    */
    'roles' => [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'team_lead' => 'Team Lead',
        'agent' => 'Agent',
        'hr' => 'HR',
        'it' => 'IT',
        'utility' => 'Utility',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Definitions
    |--------------------------------------------------------------------------
    |
    | Define all available permissions in the system.
    | Permissions are grouped by feature/module for better organization.
    |
    */
    'permissions' => [
        // Dashboard
        'dashboard.view' => 'View Dashboard',

        // User Management
        'accounts.view' => 'View User Accounts',
        'accounts.create' => 'Create User Accounts',
        'accounts.edit' => 'Edit User Accounts',
        'accounts.delete' => 'Delete User Accounts',

        // Hardware Specs (RAM, Disk, Processor, Monitor)
        'hardware.view' => 'View Hardware Specs',
        'hardware.create' => 'Create Hardware Specs',
        'hardware.edit' => 'Edit Hardware Specs',
        'hardware.delete' => 'Delete Hardware Specs',

        // PC Specs
        'pcspecs.view' => 'View PC Specs',
        'pcspecs.create' => 'Create PC Specs',
        'pcspecs.edit' => 'Edit PC Specs',
        'pcspecs.delete' => 'Delete PC Specs',
        'pcspecs.qrcode' => 'Generate PC QR Codes',
        'pcspecs.update_issue' => 'Update PC Issues',

        // Sites & Campaigns
        'sites.view' => 'View Sites',
        'sites.create' => 'Create Sites',
        'sites.edit' => 'Edit Sites',
        'sites.delete' => 'Delete Sites',
        'campaigns.view' => 'View Campaigns',
        'campaigns.create' => 'Create Campaigns',
        'campaigns.edit' => 'Edit Campaigns',
        'campaigns.delete' => 'Delete Campaigns',

        // Stations
        'stations.view' => 'View Stations',
        'stations.create' => 'Create Stations',
        'stations.edit' => 'Edit Stations',
        'stations.delete' => 'Delete Stations',
        'stations.qrcode' => 'Generate Station QR Codes',
        'stations.bulk' => 'Bulk Create Stations',

        // Stocks
        'stocks.view' => 'View Stock Inventory',
        'stocks.create' => 'Create Stock Items',
        'stocks.edit' => 'Edit Stock Items',
        'stocks.delete' => 'Delete Stock Items',
        'stocks.adjust' => 'Adjust Stock Levels',

        // PC Transfers
        'pc_transfers.view' => 'View PC Transfers',
        'pc_transfers.create' => 'Transfer PCs',
        'pc_transfers.remove' => 'Remove PC from Station',
        'pc_transfers.history' => 'View Transfer History',

        // PC Maintenance
        'pc_maintenance.view' => 'View PC Maintenance',
        'pc_maintenance.create' => 'Create Maintenance Records',
        'pc_maintenance.edit' => 'Edit Maintenance Records',
        'pc_maintenance.delete' => 'Delete Maintenance Records',

        // Attendance Management
        'attendance.view' => 'View Attendance Records',
        'attendance.create' => 'Create Attendance Records',
        'attendance.import' => 'Import Attendance Data',
        'attendance.review' => 'Review Attendance',
        'attendance.verify' => 'Verify Attendance',
        'attendance.approve' => 'Approve Attendance',
        'attendance.statistics' => 'View Attendance Statistics',
        'attendance.delete' => 'Delete Attendance Records',
        'attendance.request_undertime_approval' => 'Request Undertime Approval',
        'attendance.approve_undertime' => 'Approve/Reject Undertime Requests',

        // Employee Schedules
        'schedules.view' => 'View Employee Schedules',
        'schedules.create' => 'Create Employee Schedules',
        'schedules.edit' => 'Edit Employee Schedules',
        'schedules.delete' => 'Delete Employee Schedules',
        'schedules.toggle' => 'Toggle Schedule Active Status',

        // Biometric Records
        'biometric.view' => 'View Biometric Records',
        'biometric.reprocess' => 'Reprocess Biometric Data',
        'biometric.anomalies' => 'View Biometric Anomalies',
        'biometric.export' => 'Export Biometric Data',
        'biometric.retention' => 'Manage Retention Policies',

        // Attendance Points
        'attendance_points.view' => 'View Attendance Points',
        'attendance_points.create' => 'Create Manual Attendance Points',
        'attendance_points.edit' => 'Edit Manual Attendance Points',
        'attendance_points.delete' => 'Delete Manual Attendance Points',
        'attendance_points.excuse' => 'Excuse Attendance Points',
        'attendance_points.export' => 'Export Attendance Points',
        'attendance_points.rescan' => 'Rescan Attendance Points',

        // Leave Requests
        'leave.view' => 'View Leave Requests',
        'leave.create' => 'Create Leave Requests',
        'leave.edit' => 'Edit Leave Requests',
        'leave.approve' => 'Approve Leave Requests',
        'leave.deny' => 'Deny Leave Requests',
        'leave.cancel' => 'Cancel Leave Requests',
        'leave.delete' => 'Delete Leave Requests',
        'leave.view_all' => 'View All Leave Requests',

        // Leave Credits
        'leave_credits.view_all' => 'View All Leave Credits',
        'leave_credits.view_own' => 'View Own Leave Credits',

        // IT Concerns
        'it_concerns.view' => 'View IT Concerns',
        'it_concerns.create' => 'Create IT Concerns',
        'it_concerns.edit' => 'Edit IT Concerns',
        'it_concerns.delete' => 'Delete IT Concerns',
        'it_concerns.assign' => 'Assign IT Concerns',
        'it_concerns.resolve' => 'Resolve IT Concerns',

        // Medication Requests
        'medication_requests.view' => 'View Medication Requests',
        'medication_requests.create' => 'Create Medication Requests',
        'medication_requests.update' => 'Update Medication Requests Status',
        'medication_requests.delete' => 'Delete Medication Requests',

        // Form Requests Retention
        'form_requests.retention' => 'Manage Form Requests Retention Policies',

        // Notifications
        'notifications.send' => 'Send Notifications to Users',
        'notifications.send_all' => 'Send Notifications to All Users',

        // Settings
        'settings.view' => 'Access Settings',
        'settings.account' => 'Manage Account Settings',
        'settings.password' => 'Change Password',

        // Activity Logs
        'activity_logs.view' => 'View Activity Logs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Permissions Matrix
    |--------------------------------------------------------------------------
    |
    | Define which permissions each role has.
    | Use '*' to grant all permissions to a role.
    |
    */
    'role_permissions' => [
        'super_admin' => ['*'], // Super Admin has all permissions

        'admin' => [
            'dashboard.view',
            'accounts.view', 'accounts.create', 'accounts.edit', 'accounts.delete',
            'sites.view', 'sites.create', 'sites.edit', 'sites.delete',
            'campaigns.view', 'campaigns.create', 'campaigns.edit', 'campaigns.delete',
            'stations.view',
            'attendance.view', 'attendance.create', 'attendance.import', 'attendance.review', 'attendance.verify', 'attendance.approve', 'attendance.statistics', 'attendance.delete', 'attendance.request_undertime_approval', 'attendance.approve_undertime',
            'schedules.view', 'schedules.create', 'schedules.edit', 'schedules.delete', 'schedules.toggle',
            'biometric.view', 'biometric.reprocess', 'biometric.anomalies', 'biometric.export', 'biometric.retention',
            'attendance_points.view', 'attendance_points.create', 'attendance_points.edit', 'attendance_points.delete', 'attendance_points.excuse', 'attendance_points.export', 'attendance_points.rescan',
            'leave.view', 'leave.create', 'leave.edit', 'leave.approve', 'leave.deny', 'leave.cancel', 'leave.delete', 'leave.view_all',
            'leave_credits.view_all', 'leave_credits.view_own',
            'it_concerns.view', 'it_concerns.create', 'it_concerns.assign',
            'medication_requests.view', 'medication_requests.create', 'medication_requests.update', 'medication_requests.delete',
            'form_requests.retention',
            'notifications.send', 'notifications.send_all',
            'settings.view', 'settings.account', 'settings.password',
        ],

        'team_lead' => [
            'dashboard.view',
            'attendance.view', 'attendance.create', 'attendance.import', 'attendance.review', 'attendance.approve', 'attendance.statistics', 'attendance.delete', 'attendance.request_undertime_approval',
            'schedules.view', 'schedules.create', 'schedules.edit', 'schedules.delete', 'schedules.toggle',
            'biometric.view', 'biometric.reprocess',
            'attendance_points.view', 'attendance_points.create', 'attendance_points.export',
            'leave.view', 'leave.create',
            'leave_credits.view_all', 'leave_credits.view_own',
            'it_concerns.view', 'it_concerns.create',
            'medication_requests.view', 'medication_requests.create', 'medication_requests.update',
            'notifications.send', 'notifications.send_all',
            'settings.account', 'settings.password',
        ],

        'agent' => [
            'dashboard.view',
            'attendance.view',
            'attendance_points.view',
            'leave.view', 'leave.create', 'leave.cancel',
            'leave_credits.view_own',
            'it_concerns.create',
            'medication_requests.view', 'medication_requests.create',
            'settings.account',
        ],

        'hr' => [
            'dashboard.view',
            'accounts.view', 'accounts.create', 'accounts.edit', 'accounts.delete',
            'attendance.view', 'attendance.create', 'attendance.import', 'attendance.review', 'attendance.verify', 'attendance.approve', 'attendance.statistics', 'attendance.delete', 'attendance.request_undertime_approval', 'attendance.approve_undertime',
            'schedules.view', 'schedules.create', 'schedules.edit', 'schedules.delete', 'schedules.toggle',
            'biometric.view', 'biometric.reprocess', 'biometric.anomalies', 'biometric.export', 'biometric.retention',
            'attendance_points.view', 'attendance_points.create', 'attendance_points.edit', 'attendance_points.delete', 'attendance_points.excuse', 'attendance_points.export', 'attendance_points.rescan',
            'leave.view', 'leave.create', 'leave.edit', 'leave.approve', 'leave.deny', 'leave.delete', 'leave.view_all',
            'leave_credits.view_all', 'leave_credits.view_own',
            'medication_requests.view', 'medication_requests.create', 'medication_requests.update', 'medication_requests.delete',
            'notifications.send', 'notifications.send_all',
            'settings.account', 'settings.password',
        ],

        'it' => [
            'dashboard.view',
            'accounts.view', 'accounts.create', 'accounts.edit', 'accounts.delete',
            'hardware.view', 'hardware.create', 'hardware.edit', 'hardware.delete',
            'pcspecs.view', 'pcspecs.create', 'pcspecs.edit', 'pcspecs.delete', 'pcspecs.qrcode', 'pcspecs.update_issue',
            'sites.view', 'sites.create', 'sites.edit', 'sites.delete',
            'campaigns.view', 'campaigns.create', 'campaigns.edit', 'campaigns.delete',
            'stations.view', 'stations.create', 'stations.edit', 'stations.delete', 'stations.qrcode', 'stations.bulk',
            'stocks.view', 'stocks.create', 'stocks.edit', 'stocks.delete', 'stocks.adjust',
            'pc_transfers.view', 'pc_transfers.create', 'pc_transfers.remove', 'pc_transfers.history',
            'pc_maintenance.view', 'pc_maintenance.create', 'pc_maintenance.edit', 'pc_maintenance.delete',
            'attendance.view',
            'attendance_points.view',
            'leave.view', 'leave.create',
            'leave_credits.view_own',
            'it_concerns.view', 'it_concerns.create', 'it_concerns.edit', 'it_concerns.delete', 'it_concerns.assign', 'it_concerns.resolve',
            'medication_requests.view', 'medication_requests.create',
            'form_requests.retention',
            'notifications.send', 'notifications.send_all',
            'settings.account', 'settings.password',
        ],

        'utility' => [
            'dashboard.view',
            'attendance.view',
            'attendance_points.view',
            'leave.view', 'leave.create',
            'leave_credits.view_own',
            'settings.account',
        ],
    ],
];

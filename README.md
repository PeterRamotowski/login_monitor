# Login Monitor

The Login Monitor module provides comprehensive tracking and monitoring of user
login activities on your Drupal site. It logs successful logins, failed login
attempts, and logout events, while offering configurable email notifications
and periodic statistical reports to help administrators monitor site security
and user activity patterns.


## Table of contents

- Requirements
- Installation
- Configuration
- Features
- Drush commands
- Troubleshooting
- FAQ
- Support
- Maintainers


## Requirements

Token module is required:

- [Token](https://www.drupal.org/project/token)

Core System, User and Views modules must be installed and enabled.


## Installation

Install as you would normally install a contributed Drupal module.
For further information, see [Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-modules).


## Configuration

1. Enable the module at Administration » Extend.
1. Navigate to Administration » Configuration » People » Login monitor settings
   (`/admin/config/people/login-monitor`) to configure the module.
1. Configure the following settings:
   - **Enable login logging**: Toggle logging of login events
   - **Send email notifications**: Enable real-time email notifications for
     login events
   - **Tracked user roles**: Select which user roles should be monitored
   - **Email recipient**: Email address to receive notifications
   - **Email content**: Customize the notification email template using tokens
   - **Enable log cleanup**: Automatically remove old log entries
   - **Log retention days**: Number of days to retain login logs
   - **Enable email reports**: Send periodic statistical reports
   - **Report frequency**: Choose daily, weekly, or monthly reports
   - **Report recipient**: Email address to receive statistical reports
1. Set permissions at Administration » People » Permissions:
   - **Administer Login Monitor settings**: Allows users to configure module
     settings
   - **View login log entities**: Allows users to view login logs
   - **Administer login log entities**: Allows full access to login log
     entities including deletion


## Features

### Login Activity Logging
- Tracks successful logins (including one-time login links)
- Logs failed login attempts (invalid users, valid users, blocked users)
- Records logout events
- Stores IP addresses, user agents, and timestamps

### Email Notifications
- Real-time email notifications for login events
- Configurable email templates with token support
- Role-based filtering for notifications
- Customizable recipient addresses

### Statistical Reports
- Periodic email reports (daily, weekly, monthly)
- Login activity summaries and statistics
- Failed login attempt analysis
- User activity patterns

### Administrative Interface
- Login log viewer at Administration » Reports » Login Log
  (`/admin/reports/logins`)
- Filterable and sortable login event listings
- Detailed event information including IP addresses and user agents

### Data Management
- Configurable log retention periods
- Automatic cleanup of old log entries
- Bulk operations for log management


## Drush commands

The module provides the following Drush command:

```bash
drush login-monitor:send-reports
```

This command manually triggers the sending of statistical reports. It's useful
for testing report functionality or sending reports outside of the normal
schedule.


## Troubleshooting

### Email notifications are not being sent

Check the following:

- Verify that "Send email notifications" is enabled in the module settings
- Ensure a valid email recipient is configured
- Check that the user's role is included in the "Tracked user roles" setting
- Verify your site's email configuration is working correctly
- Check the site logs for any email-related errors

### Login events are not being logged

Check the following:

- Verify that "Enable login logging" is enabled in the module settings
- Ensure the user's role is included in the "Tracked user roles" setting
- Review site logs for any database-related errors

### Reports are not being sent automatically

Check the following:

- Verify that "Enable email reports" is enabled
- Ensure cron is running regularly on your site
- Check that a valid report recipient email is configured


## FAQ

**Q: Can I monitor login attempts for specific user roles only?**

**A:** Yes, use the "Tracked user roles" setting to specify which roles should
be monitored. Leave empty to monitor all users.

**Q: How do I customize the email notification content?**

**A:** Edit the "Email content" field in the module settings. You can use
tokens like `[login_monitor:event_type_label]`, `[login_monitor:username]`,
`[login_monitor:ip_address]`, and standard user tokens.

**Q: Can I export login data for analysis?**

**A:** Yes, the login log uses Views, so you can create custom Views to export
data in various formats (CSV, XML, etc.) or integrate with other modules.

**Q: What happens to old login logs?**

**A:** If log cleanup is enabled, logs older than the configured retention
period will be automatically deleted during cron runs. Otherwise, logs are
retained indefinitely.

**Q: Can I disable logging for certain login methods?**

**A:** The module logs all login events but provides filtering options. You can
configure role-based tracking to exclude certain user types from monitoring.


## Similar modules

- [ECA: Event - Condition - Action](https://www.drupal.org/project/eca): A comprehensive event-driven automation framework that can handle user login events among many other Drupal events. While ECA is more powerful and flexible, it requires significant configuration and technical knowledge to set up login monitoring workflows. ECA is ideal for complex automation needs but overkill if you only need login monitoring.
- [Events Log Track](https://www.drupal.org/project/events_log_track): Log more event types than just user logins. No notifications and reports.
- [Login History](https://www.drupal.org/project/login_history): Doesn't log failed login attempts, missing role tracking. No notifications and reports. Provides block with information about the user's last login.
- [User Login Tracker](https://www.drupal.org/project/user_login_tracker): Lacks some features like email notifications and reports. Doesn't log failed login attempts, missing role tracking. Not compatible with Drupal 11.
- [Login Notification](https://www.drupal.org/project/login_notification): Not compatible with Drupal 11. Notify via on page messages instead of email. No reports or detailed logging.
- [User Update Notify](https://www.drupal.org/project/user_update_notify): Doesn't save logs. Focuses on user updates, not login events. Send email notifications.
- [Login tracker](https://www.drupal.org/project/login_tracker): No further development. Not compatible with Drupal 11.
- [Login Activity](https://www.drupal.org/project/login_activity): No further development. Not compatible with Drupal 11. Provides basic login tracking without advanced features like email notifications or reports.
- [Sign Up Tracker](https://www.drupal.org/project/sign_up_tracker): Logs only user registrations. Not compatible with Drupal 11.


## Support

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/login_monitor).


## Maintainers

- Piotr Ramotowski - [ramotowski](https://www.drupal.org/u/ramotowski)

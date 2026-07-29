=== EW User Cleaner ===
Contributors: ewallzsolutions
Tags: spam users, spam registrations, user cleanup, delete users, bulk delete users
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find, review, quarantine, purge and restore likely spam user registrations. Nothing is deleted automatically and every purge is backed up first.

== Description ==

Spam signups accumulate quietly. Deleting them in bulk is risky, because a bad rule can remove real customers along with the junk.

EW User Cleaner separates the two steps. It scores accounts against rules you control, then makes you review the results before anything happens. Removal is staged and reversible at every step.

* Nothing is deleted automatically. Scanning only writes to the plugin's own tables.
* Quarantine blocks sign in without touching the account. Roles, content and orders stay intact, and restoring returns the same user ID.
* Every purge is backed up first, encrypted, so even permanent deletion can be undone.
* Runs in bounded batches while you watch. No background cron jobs and no surprise mass deletions.

= How scoring works =

Each account is checked against the rules you enable. Matching rules add their weight, and an account becomes a review candidate once the total reaches your threshold.

* Username looks like a phone number
* Username equals the email local part
* Email local part looks like a phone number
* Email domain is in your flagged domain list, subdomains included
* Username matches your custom pattern
* Email local part matches your custom pattern
* Blocklist entry for exact usernames or emails

The Help tab ships 20 ready made patterns with examples of what each one matches and ignores, so you can copy one instead of writing a regular expression yourself.

= Allowlists always win =

Allowlist checks run before any rule. A match scores zero and skips scoring entirely. You can allow email domains including their subdomains, exact usernames, exact emails, and specific user IDs.

= Safety model =

Some accounts can never be quarantined or purged, whatever the rules say. Protection is re-evaluated at the moment of action, not just at scan time, so a role change between scanning and purging is respected.

Hard protections that can never be overridden cover the signed in administrator, user ID 1, protected roles, privileged capabilities and the configured content reassignment account.

Accounts that own posts, comments or WooCommerce orders are skipped by default. You can override that, but only after setting a content reassignment user, so their content transfers instead of being deleted.

Every state change is written to an audit log.

= Backups and restore =

Purging writes an encrypted backup of the user record and metadata before deletion, using XChaCha20-Poly1305 where available and AES-256-GCM otherwise. Keys are derived from your wp-config.php salts, so backups are not portable to another site.

Restored accounts receive a new user ID, because the original is gone. Some references cannot always be reattached, in which case the restore reports itself as partial.

= Built for large sites =

Scans use keyset pagination rather than OFFSET, so query cost does not grow as the scan progresses, and each request stops at a 12 second work budget. Scan, quarantine and purge batch sizes are all tunable for shared hosting with short PHP timeouts.

= Support =

Built and maintained by eWallz Solutions, WordPress customization experts.

* Website: https://www.ewallzsolutions.com
* Email: hello@ewallzsolutions.com
* WhatsApp: https://wa.me/60355230791

== Installation ==

1. Upload the `ew-user-cleaner` folder to `/wp-content/plugins/`, or install the ZIP through Plugins, Add New.
2. Activate the plugin through the Plugins menu.
3. Go to Users, User Cleaner.

Activation creates five database tables and grants the plugin capabilities to the administrator role.

Nothing is scanned until you configure the rules. Default weights are set, but no candidate threshold is, so the plugin refuses to scan until you make that decision.

= Getting started =

1. Open the Settings tab, set a candidate threshold, enable the rules you want, then save. Set the threshold above the weight of any single rule so no lone signal is enough to flag someone.
2. Click Estimate impact to score the 200 newest accounts without saving anything. If it flags almost everyone, your threshold is too low.
3. On the Dashboard, click Start new scan and leave the tab open.
4. Review the Candidates tab page by page. Mark anything legitimate as legitimate.
5. Quarantine the rest, either the rows you select or every matching row in batches.
6. Leave them quarantined for a week or two. If nobody complains, purge from the Quarantine tab.

== Frequently Asked Questions ==

= Will this delete users automatically? =

No. Scanning only writes to the plugin's own tables. Quarantine and purge are both manual actions you trigger yourself, and purge additionally requires typing a confirmation phrase.

= What is the difference between quarantine and purge? =

Quarantine blocks sign in and ends active sessions, but the account and all its data remain untouched. It is fully reversible and restores the same user ID.

Purge permanently deletes the account after writing an encrypted backup. It can only be undone by restoring that backup, which produces a new user ID.

= Is multisite supported? =

Not in version 1. On multisite, `wp_delete_user()` only detaches a user from the current site instead of deleting the record, so destructive actions are disabled rather than behaving incorrectly.

= My scan found nothing. =

Check that your threshold is not higher than the combined weight of your enabled rules, and that the rules you expect are actually enabled.

= My flagged domain is not matching. =

Confirm the "Email domain is in the flagged domain list" rule is enabled. The list is inert while that rule is switched off. The plugin warns you on save if the list is populated but the rule is off.

= Results look wrong after I changed the settings. =

Every scan stores an immutable snapshot of your rules at the moment it starts, so results always reflect the policy that produced them. The Dashboard flags the scan as stale after a settings change. Run a new scan.

= The purge buttons are missing. =

Purge requires the `ewuc_purge_users` capability, a non-multisite install, and a working Sodium or OpenSSL extension with valid salt constants in wp-config.php. Without encryption available, scanning, review and quarantine still work.

= Can I let a moderator review without letting them delete? =

Yes. Six separate capabilities control settings, scanning, reviewing, quarantining, purging and restoring. Assign them to other roles with any role editor to split duties.

= What happens if I deactivate the plugin? =

Quarantine stops blocking logins, because that depends on the plugin being active, so quarantined users regain access. All data is retained.

= Does uninstalling delete my data? =

Not unless you tick "Delete all plugin data when the plugin is uninstalled" in Settings first. Even then, uninstall refuses to delete anything while accounts remain quarantined, so you cannot orphan a quarantine state.

= Are the detection rules accurate? =

They are heuristics based on local identity data, not a reputation service. That is exactly why the review step exists. Always check the queue instead of assuming it is correct.

== Screenshots ==

1. Dashboard with scan progress, review queue counts and backup storage.
2. Candidate review with scores and the reason each account matched.
3. Settings with scoring rules, weights and allowlists.
4. Help tab with ready made patterns you can copy.
5. Quarantine tab with restore and purge actions.

== Changelog ==

= 1.2.0 =
* Added: "Quarantine all matching" on the Candidates tab, processing every awaiting-review row in bounded batches instead of one page at a time.
* Added: Warning when a selection exceeds the per-request quarantine batch size, instead of silently truncating it.
* Added: Warning when the flagged domain list is populated but the domain rule is disabled.
* Changed: Allowed email domains are now a full allowlist. A matching address scores zero and skips every other rule.
* Changed: A leading dot in a domain list entry is now ignored, so `.att.net` and `att.net` behave identically and both cover subdomains.
* Added: Plugin and support information on the Dashboard.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.0 =
Allowed email domains now skip every rule, not just the domain rule, and a leading dot in a domain entry no longer excludes the apex domain. Run a new scan after upgrading so results reflect the updated matching.

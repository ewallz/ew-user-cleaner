# EW User Cleaner

Find, review, quarantine, purge and restore likely spam user registrations in WordPress.

Spam signups accumulate quietly. Deleting them in bulk is risky, because a bad rule can remove real customers along with the junk. EW User Cleaner separates the two steps: it **scores** accounts against rules you control, then makes you **review** the results before anything happens. Removal is staged and reversible at every step.

- **Nothing is deleted automatically.** Scanning only writes to the plugin's own tables.
- **Quarantine blocks sign in without touching the account.** Roles, content and orders stay intact, and restoring returns the same user ID.
- **Every purge is backed up first,** encrypted, so even permanent deletion can be undone.
- **Runs in bounded batches while you watch.** No background cron jobs, no surprise mass deletions.

---

## Requirements

| | |
|---|---|
| WordPress | 6.4 or newer |
| PHP | 8.0 or newer |
| Multisite | Not supported in v1 (see [Limitations](#limitations)) |

Purge requires either the **Sodium** or **OpenSSL** PHP extension for backup encryption, plus the standard salt constants in `wp-config.php`. Almost every host has one of these. Without it, scanning, review and quarantine still work; only permanent purge is disabled.

---

## Installation

1. Copy the `ew-user-cleaner` folder into `wp-content/plugins/`.
2. Activate **EW User Cleaner** in **Plugins**.
3. Go to **Users → User Cleaner**.

Activation creates five database tables (`ewuc_jobs`, `ewuc_candidates`, `ewuc_quarantine`, `ewuc_backups`, `ewuc_audit`) and grants the plugin capabilities to the administrator role.

Nothing is scanned until you configure the rules, which is deliberate. Default weights are set, but no candidate threshold is, so the plugin refuses to scan until you make a decision.

---

## Quick start

**1. Configure the rules.** Open the **Settings** tab, set a **candidate threshold**, enable the rules you want, and save.

The threshold is the total score at which an account becomes a review candidate. Set it *above* the weight of any single rule so that no lone signal is enough to flag someone. With the defaults, a threshold of `2` or `3` is a sensible starting point.

**2. Estimate the impact.** Click **Estimate impact** to score the 200 newest accounts without saving anything. If it flags almost everyone, your threshold is too low.

**3. Scan.** On the **Dashboard**, click **Start new scan** and leave the tab open. Progress is saved after each batch, so you can pause or close the page and resume later.

**4. Review.** The **Candidates** tab lists matches with a score and a plain-English reason for each. Work through it page by page. Anything legitimate gets **Mark as legitimate**; the rest gets quarantined.

**5. Quarantine.** Select rows and choose **Quarantine selected**, or use **Quarantine all matching** to work through the entire filtered list in batches. Quarantined users cannot sign in and are logged out of active sessions immediately, but no data is removed.

**6. Wait, then purge.** Leave accounts quarantined for a week or two. If nobody complains, go to the **Quarantine** tab and purge. Each purge writes an encrypted backup before deleting.

---

## How scoring works

Each account is checked against the enabled rules. Matching rules add their weight, and an account becomes a candidate once the total reaches your threshold.

| Rule | Default weight | Matches |
|---|---|---|
| Username looks like a phone number | 2 | `0123456789` |
| Username equals email local part | 1 | `pam` / `pam@example.com` |
| Email local part looks like a phone number | 2 | `0123456789@example.com` |
| Email domain is in the flagged domain list | 2 | Your flagged domains, including subdomains |
| Username matches custom pattern | 2 | Your regular expression |
| Email local part matches custom pattern | 2 | Your regular expression |
| Blocklist entry | 3 | Exact usernames or emails you listed |

Phone-like detection is conservative: it only tolerates separators that genuinely appear in phone numbers, and the digit count must fall within the configured min/max.

The **Help** tab ships 20 ready-made patterns with examples of what each one matches and ignores, so you can copy one instead of writing a regular expression yourself. Patterns are validated on save and rejected if they cannot compile or look prone to catastrophic backtracking.

### Allowlists always win

Allowlist checks run **before** any rule. A match scores zero and short circuits scoring entirely:

- **Allowed email domains** — subdomains included, so `example.com` also covers `mail.example.com`
- **Allowed usernames** and **allowed emails** — exact matches
- **Always protected user IDs**

Be deliberate about allowing large public providers like `gmail.com`. It will silence false positives, but spam registered there becomes invisible to scanning too.

### Flagged domain list

Entries match the domain and all its subdomains. A leading dot is accepted and ignored, so `att.net` and `.att.net` behave identically. Matching is on label boundaries, so `att.net` never matches `notatt.net`.

This list only does something when the **Email domain is in the flagged domain list** rule is enabled. If you fill in the list while the rule is off, the plugin warns you on save rather than ignoring it silently.

### Scans use a rule snapshot

Every scan stores an immutable copy of your rules at the moment it starts, so results always reflect the policy that produced them. Change your settings afterwards and the Dashboard flags the scan as stale. **Run a new scan after changing rules**, otherwise you are reviewing results from the old policy.

---

## Safety model

Some accounts can never be quarantined or purged, no matter what the rules say. Protection is re-evaluated at the moment of action, not just at scan time, so a role change between scanning and purging is respected.

**Hard protections, never overridable:**

| Code | Meaning |
|---|---|
| `current_user` | The administrator currently signed in |
| `user_one` | User ID 1, when enabled in Settings |
| `protected_role` | Holds a protected role (administrator is always protected) |
| `protected_cap` | Holds a privileged capability |
| `reassign_target` | The configured content reassignment account |

**Soft protection:** `owns_data` marks accounts that own posts, comments or WooCommerce orders. These are skipped by default. You can override it, but only after setting a **content reassignment user**, so their content transfers to that account instead of being deleted.

Every state change is written to the **Audit** tab.

---

## Backups and restore

Purging writes an encrypted backup of the user record and metadata before deletion, using XChaCha20-Poly1305 where available and AES-256-GCM otherwise. Keys are derived with HKDF from your `wp-config.php` salts, so backups are not portable to another site.

Restore from the **Backups** tab by entering the original user ID. Restored accounts get a **new** user ID, since the original is gone. Some references cannot always be reattached, in which case the restore reports itself as partial.

Backups contain personal data and are kept until you delete them. Under GDPR-style regimes this is your retention decision to make, so clear out batches you no longer need.

---

## Capabilities

Six capabilities are granted to the administrator role on activation. Assign them to other roles with any role editor to split duties, for example letting a moderator review and quarantine but never purge.

| Capability | Grants |
|---|---|
| `ewuc_manage_settings` | Change rules and settings |
| `ewuc_scan_users` | Start, pause and resume scans |
| `ewuc_review_users` | View candidates, dismiss as legitimate |
| `ewuc_quarantine_users` | Quarantine accounts |
| `ewuc_purge_users` | Permanently purge, delete backups |
| `ewuc_restore_users` | Restore from quarantine or backup |

---

## Performance

Built for large user tables. Scans use keyset pagination on `user_id` rather than `OFFSET`, so query cost does not grow as the scan progresses, and each request stops at a 12-second work budget.

Three batch sizes are tunable under **Settings → Performance**:

| Setting | Default | Range |
|---|---|---|
| Users per scan request | 250 | 25–1000 |
| Users per quarantine request | 25 | 5–100 |
| Users per purge request | 10 | 1–50 |

Lower them on shared hosting that enforces short PHP timeouts. Pattern matching runs in PHP, never in SQL, and searches are prefix-only so they can use an index.

---

## Exporting

The **Candidates** tab exports the current filter to CSV, including scores and reasons. Useful for a second opinion before quarantining. Cell values that begin with `=`, `+`, `-` or `@` are prefixed with an apostrophe so spreadsheet software cannot execute them as formulas.

---

## Uninstalling

Deactivating the plugin **stops quarantine from blocking logins**, because that depends on the plugin being active. Quarantined users regain access. All data is retained.

Uninstalling keeps your data unless you tick **Delete all plugin data when the plugin is uninstalled** in Settings first. Even then, uninstall refuses to delete anything while accounts remain quarantined, so you cannot orphan a quarantine state.

---

## Limitations

- **Multisite is not supported.** `wp_delete_user()` only detaches a user from the current site instead of deleting the record, so destructive actions are disabled on multisite rather than behaving incorrectly.
- **Scans need the tab open.** There is no cron worker. Progress is persisted, so closing the tab pauses rather than loses work.
- **Detection is probabilistic.** These are heuristics on local identity data, not a reputation service. Review the queue; do not assume it is correct.
- **Search is prefix-only,** so that it can use a database index on large sites.

---

## Troubleshooting

**A scan found nothing.** Check that the threshold is not higher than the combined weight of your enabled rules, and that the rules you expect are actually enabled.

**A flagged domain is not matching.** Confirm the **Email domain is in the flagged domain list** rule is enabled. The list is inert while that rule is off.

**Results look wrong after changing settings.** The Dashboard will say the scan is stale. Run a new scan; results reflect the snapshot taken when the scan started.

**Purge buttons are missing.** Purge needs the `ewuc_purge_users` capability, a non-multisite install, and a working encryption extension with valid salts in `wp-config.php`.

**A UI button does nothing after an update.** Hard-refresh with `Ctrl`+`F5`. If you use a caching or asset-minification plugin, clear its cache too.

**How are new versions delivered?** When an administrator opens **Plugins → Installed Plugins**, EW User Cleaner checks GitHub at most once every 12 hours. If a newer stable release exists, a notice links to its GitHub Release page for manual download. The plugin never enables one-click or automatic updates.

---

## Releasing

The version is declared in three places, and the release workflow refuses to publish unless all three match the git tag:

| File | What to change |
|---|---|
| `ew-user-cleaner.php` | the `Version:` header |
| `ew-user-cleaner.php` | the `EWUC_VERSION` constant |
| `readme.txt` | `Stable tag:` |

Update all three, add a `readme.txt` changelog entry, then:

```powershell
git add -A
git commit -m "Release 1.3.0"
git tag -a v1.3.0 -m "Release 1.3.0"
git push --follow-tags
```

The tag must be annotated (`-a`). `--follow-tags` pushes annotated tags only, so a lightweight `git tag v1.3.0` is skipped silently: the branch pushes, no tag reaches the remote, and no release runs. Verify with `git ls-remote --tags origin | Select-String "v1.3.0"`.

Pushing the tag triggers `.github/workflows/release.yml`, which verifies the versions, builds `ew-user-cleaner-<version>.zip`, checks the archive structure, and publishes a GitHub Release with the zip attached. A version mismatch fails the run before anything is published.

Files marked `export-ignore` in `.gitattributes` (this README, `.github/`, dotfiles) are excluded from the zip, so the release contains only what WordPress needs.

---

## Support

Built and maintained by **eWallz Solutions**, WordPress customization experts.

- Website: <https://www.ewallzsolutions.com>
- Email: <hello@ewallzsolutions.com>
- WhatsApp: <https://wa.me/60355230791>

For custom plugin development, performance audits or help with this plugin, get in touch.

---

## License

GPL-2.0-or-later. See <https://www.gnu.org/licenses/gpl-2.0.html>.

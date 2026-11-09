=== Sepo Finance ===
Contributors: euneolink
Tags: accounting, bookkeeping, invoices, ledger, finance
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.4
License: GPLv2 or later

Money in, money out, and where the business stands, kept inside WordPress.

== Description ==

Sepo Finance keeps the books for a small organisation from the WordPress dashboard. It needs no outside service.

* **Ledger.** Record money in, money out and transfers between accounts. A quick-add row keeps the date, account and category for the next line. Dates are read day first (13/09/2026), and amounts can be typed as 1,200, K1,200.50 or (450).
* **Accounts.** Bank, mobile money, cash and card. Only the opening balance is typed in; every later balance is worked out from the ledger.
* **Categories, customers and suppliers.** Each can be added on the spot from any form. A customer or supplier has a statement showing what is owed each way.
* **Invoices and bills.** Add items and the tax and total are worked out for you. Record part payments. Print an invoice or email it, and turn on automatic reminders for overdue invoices.
* **Recurring entries.** Rent, salaries and subscriptions wait on a "due now" list, and you post or skip each one. Nothing is posted without you.
* **Reconcile.** Tick the entries on a bank statement until the difference is zero.
* **Import.** Bring in a bank or mobile money statement as CSV. Rows already in the ledger are found and left unticked.
* **Receipts.** Attach private files, or files from the media library, to any entry. Private files are served only to people with Finance access.
* **Reports.** Profit and loss, cash flow, categories, cash position, aged balances and a tax summary. Each one can be printed or downloaded as CSV or JSON.
* **Activity log.** Every change to the books is recorded: who made it, and what changed.

= Who can use it =

Administrators, and the **Bookkeeper** role the plugin adds. A bookkeeper can keep the books but cannot change the rest of the site. Only administrators can change the Finance settings.

= Sepo Membership =

When Sepo Membership is active, each payment confirmed there is recorded as income in the account you choose in Settings. A payment that is later rejected or deleted in Membership is taken back out of the ledger.

= How the numbers are counted =

* Reports count money that has moved. Pending entries are listed but left out of balances and reports until they clear.
* Transfers between your own accounts are neither income nor expense.
* Accounts in another currency are listed but not added into the main-currency totals.
* The tax summary treats amounts as including tax. At 16%, the tax in an amount of 116 is 16. Treat the summary as an estimate to check, not as a tax return.

== Installation ==

1. Upload the `sepo-finance` folder to `/wp-content/plugins/` and activate the plugin.
2. Open **Finance → Settings** and set the currency, the tax rate and your business details.
3. Add your accounts under **Finance → Accounts**, each with the balance it held on the day you start.

== Frequently Asked Questions ==

= Can it post recurring entries automatically? =

No. Recurring entries are listed when due and you post each one yourself, so nothing reaches the books before the money has moved.

= What happens when I delete the plugin? =

Nothing is removed unless you tick "Delete all finance records and files when the plugin is deleted" in Settings first.

== Screenshots ==

1. The dashboard: cash position, money in and out, and what needs attention.

== Changelog ==

= 1.0.4 =
* The plugin is by Euneolink Solutions. Its plugin page and author page are edu.sepodesk.com.

= 1.0.3 =
* Readme: screenshots and their captions for the plugin directory.

= 1.0.2 =
* Admin screens: settings shown as cards, lists in a rounded frame, rounded fields and buttons, pill-shaped tabs and filters, and notices on tinted backgrounds.

= 1.0.1 =
* Attachments are stored and served through the WordPress file-system API.
* Report and ledger exports read their filters with nonce-checked, sanitised values.
* Passes the WordPress Plugin Check: translator comments on every string with placeholders, escaped output, sanitised request data and documented database access to the plugin's own tables.
* Tested up to WordPress 7.1.

= 1.0.0 =
* First release.

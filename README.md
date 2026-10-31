# Sepo Finance

Bookkeeping for a small organisation, inside WordPress. It tracks money in, money out and where the business stands.
Everything runs from the WordPress dashboard. No outside service is needed.
<p align="center">
  <a href="https://edu.sepodesk.com/sponsor/projects-maintenance/">
    <img src="https://img.shields.io/badge/%E2%9D%A4%20SUPPORT%20OUR%20OPEN--SOURCE%20PROJECTS-0b6b45?style=for-the-badge&logo=githubsponsors&logoColor=white" alt="Support Our Open-Source Projects" />
  </a>
</p>

<p align="center">
  Your support helps us maintain, improve and build open-source software for everyone.
  <br />
  <strong>Every contribution makes a difference. Thank you for supporting our work!</strong>
</p>

![Version](https://img.shields.io/badge/version-1.0.0-0b3d33)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)



## Contents

- [Features](#features)
- [Who can use it](#who-can-use-it)
- [How the numbers are counted](#how-the-numbers-are-counted)
- [Requirements](#requirements)
- [Installation](#installation)
- [Getting started](#getting-started)
- [Working with Sepo Membership](#working-with-sepo-membership)
- [Uninstalling](#uninstalling)
- [FAQ](#faq)
- [Changelog](#changelog)
- [License](#license)

## Features

- **Ledger.** Record money in, money out and transfers between accounts.
  - A quick-add row keeps the date, account and category for the next line.
  - Dates are read day first, for example `13/09/2026`.
  - Amounts can be typed as `1,200`, `K1,200.50` or `(450)`.
- **Accounts.** Bank, mobile money, cash and card. You type only the opening balance. Every later balance is worked out from the ledger.
- **Categories, customers and suppliers.** Add each one on the spot from any form. Each customer and supplier has a statement showing what is owed each way.
- **Invoices and bills.**
  - Add items and the plugin works out the tax and total.
  - Record part payments.
  - Print an invoice or email it.
  - Turn on automatic reminders for overdue invoices.
- **Recurring entries.** Rent, salaries and subscriptions wait on a "due now" list. You post or skip each one. Nothing is posted without you.
- **Reconcile.** Tick the entries on a bank statement until the difference is zero.
- **Import.** Bring in a bank or mobile money statement as CSV. Rows already in the ledger are found and left unticked.
- **Receipts.** Attach private files, or files from the media library, to any entry. Private files are served only to people with Finance access.
- **Reports.** Each one can be printed or downloaded as CSV or JSON:
  - profit and loss;
  - cash flow;
  - categories;
  - cash position;
  - aged balances;
  - tax summary.
- **Activity log.** Every change to the books is recorded, with who made it and what changed.

## Who can use it

Administrators can use Finance, and so can the **Bookkeeper** role that the plugin adds. A bookkeeper can keep the books but cannot change the rest of the site. Only administrators can change the Finance settings.

## How the numbers are counted

- Reports count money that has moved. Pending entries are listed, but they stay out of balances and reports until they clear.
- Transfers between your own accounts are neither income nor expense.
- Accounts in another currency are listed but not added into the main-currency totals.
- The tax summary treats amounts as including tax. For example, at 16% the tax in an amount of 116 is 16. Treat the summary as an estimate to check, not as a tax return.

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 8.1 |

## Installation

### Upload the zip (recommended)

1. Download `sepo-finance.zip`.
2. In your WordPress dashboard, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the zip file and click **Install Now**.
4. Click **Activate**.

### Upload the folder

1. Unzip the download.
2. Copy the `sepo-finance` folder into `wp-content/plugins/` on your server.
3. Go to **Plugins** and activate **Sepo Finance**.

> [!NOTE]
> GitHub's **Code → Download ZIP** names the folder after the branch, for example `sepo-pfinance-main`. Rename it to `sepo-finance` before uploading.

## Getting started

1. Open **Finance → Settings**. Set the currency, the tax rate and your business details.
2. Add your accounts under **Finance → Accounts**. Give each one the balance it held on the day you start.
3. Start recording in **Finance → Ledger**, or import a statement.
4. To give someone access to the books only, create a user (or edit an existing one) and give them the **Bookkeeper** role.

## Working with Sepo Membership

When Sepo Membership is also active, each payment confirmed there is recorded as income. It goes into the account you choose in **Finance → Settings**. If a payment is later rejected or deleted in Membership, it is taken back out of the ledger.

## Uninstalling

**Deactivating** the plugin (**Plugins → Deactivate**) stops it and keeps everything. The books are still there when you activate it again.

**Deleting** the plugin (**Plugins → Deactivate**, then **Delete**) removes the plugin files. What happens to your records depends on one setting:

- **By default all records are kept.** That includes the ledger, accounts, invoices, bills, the activity log and receipts.
- **To remove everything**, first tick **Delete all finance records and files when the plugin is deleted** under **Finance → Settings** and save, then delete the plugin. This removes:
  - all finance tables, including the activity log;
  - the settings;
  - the Bookkeeper role;
  - the private receipts folder.

Files you attached from the media library stay in the library either way.

> [!WARNING]
> Deleting financial records cannot be undone. Download your reports first if you may need them.

## FAQ

**Can it post recurring entries automatically?**
No. Recurring entries are listed when they are due and you post each one yourself. That way nothing reaches the books before the money has moved.

**Where are receipts stored?**
Private uploads go into a protected folder inside `wp-content/uploads/`. They are served only to people with Finance access.

## Changelog

### 1.0.0
- First release.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)

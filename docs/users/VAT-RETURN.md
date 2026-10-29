# VAT return, deemed supplies and vouchers

Audience: accountants, owners and anyone who files the VAT return.

The return drafted from your sales and purchases, and two kinds of supply
whose VAT the platform works out for you. Purchases, input VAT and
receivables are in [BOOKS.md](BOOKS.md).

## VAT return

**VAT return** drafts the return box by box from your issued invoices and the
purchases in Books. Choose the period (**From**, **To**; up to 400 days) and
type in the figures only you know, such as corrections or tax carried forward.
Then **Draft the return**.

- Saudi Arabia: ZATCA's return, boxes 1 to 16, in SAR.
- UAE: VAT 201, boxes 1 to 14, in AED, with standard-rated sales split by emirate.
  A sale with no emirate is flagged: give each branch its emirate.

Click a figure to see every document behind it. **Download CSV** or
**Download PDF**.

It is a draft. Check it, then file it yourself with the authority.

### Copying a Saudi return into the ZATCA form

- **Adjustment** figures are signed: reductions are below zero. Type them as
  the form asks for each box.
- In boxes 7 to 9, the adjustment includes the share of input VAT you may not
  deduct (your deductible share below 100%), and the VAT column is only what
  you deduct.
- The **9-due** line is the VAT you assess yourself on reverse-charge imports.
  It is already in box 13: you declare it and deduct it in the same return,
  so a fully deductible one costs nothing.
- Corrections of earlier returns (up to SAR 5,000) and credit carried forward
  are typed in above the boxes.

### Filing status with ZATCA

The sales boxes count every invoice and note issued in the period that ZATCA
has not rejected, whether ZATCA has answered yet or not. **Filing status with
ZATCA**, below the boxes, splits the same sales:

| Line | What it holds |
|---|---|
| Cleared or reported by ZATCA | Accepted, with or without warnings |
| Not answered yet | Not sent yet, queued, or sent with no answer |
| Not filed | Did not get through; it is sent again |
| Total sales, as box 6 | The three lines together: equal to box 6 |
| Rejected by ZATCA | Left out of the return; issue a corrected invoice |

Click a line to see its documents. If any are not answered or not filed, the
return says how many: file them before you file the return. What ZATCA answers
after you mark a period filed does not change its kept figures.

### Marking a period filed

When you have filed the return, an owner, admin or accountant uses **Mark this
period filed** with the filing date. Its figures are kept as filed. Later:

- a purchase or payment dated in it counts in the next open period;
- voiding a purchase in it, or recording a deemed supply dated in it, is
  refused: record a credit note, or correct it in an open period;
- a sale dated in it after filing is shown as **Changed since it was filed**
  on that period's page, and on the next open period's return as a warning
  with the net VAT it moved. Saudi Arabia: under SAR 5,000, correct it in that
  return's corrections from previous periods (box 14); above, notify ZATCA
  (VAT Implementing Regulations Art 63). UAE: up to AED 10,000, correct it in
  that return; above, a voluntary disclosure (Tax Procedures Law Art 10).
  Which sign the correction takes is for your adviser; the page gives the
  amount. The last 12 filed periods are checked.

**Filed periods** lists every period filed. Only an owner can **Reopen** one,
with a reason; the reopening is on the activity log.

A period is a calendar month or a quarter: it ends on a month's last day and
runs one month or three. The first period you file may start later in its
month (from your registration). After that, periods are filed in order: one
that would leave a month unfiled since the last filed period is refused, with
the dates to file first. The kept figures include the filing-status lines.

The return also points out what it does not show by itself: a taxed deemed
supply whose kept invoice is still a draft (its VAT reaches box 1 only once it
is issued), and contract retention still held after the day its tax fell due.
How each is reported waits on your tax adviser; the return only names them.
A relieved sale in a foreign currency with no exchange rate is named with the
sales left out for the same reason, never counted at zero.

The return is the whole organization's: one return per period for the
taxable person (KSA VAT Implementing Regulations Art 59; UAE VAT
Executive Regulation, Cabinet Decision 52 of 2017, Art 64). It always covers every branch, for sales and
purchases alike, whatever the branch switcher shows. Only someone who sees
every branch (an owner, an administrator, the accountant, or a member not
limited to branches) can open it; anyone limited to some branches is sent
back to Books with that reason. For figures per branch, use **Sales reports**
grouped by branch; they are not a return.

<!-- source: resources/views/portal/books/vat-return.blade.php; app/Domains/Books/Http/Concerns/ReadsBooksPeriod.php; app/Domains/Books/Http/Controllers/PortalVatReturnController.php -->

## Deemed supplies

**Books › Deemed supplies** records goods or services that leave the
business without a sale, on which you deducted input tax: taken for your own
or another non-business use, given as a gift or sample, given to an
employee, or kept when you deregister. Give the value - what it cost you, or
its market value where the cost is unknown and on deregistration - and the
share of its input tax you deducted. Each is decided at once:

| Outcome | When |
|---|---|
| **Not a deemed supply** | You deducted no input tax on it (KSA VAT Implementing Regulations Art 15(9); UAE Decree-Law Art 12(1)) |
| **Relieved** | Saudi Arabia: a gift, sample or something given to an employee while that recipient's total stays within SAR 200 in the calendar year, and all such free supplies within SAR 50,000 (Art 15(2), (3), (5), (6)). UAE: a gift or sample while that recipient's total stays within AED 500 over 12 months (Executive Regulation Art 5(1)), or while the output tax on all your deemed supplies over 12 months stays within AED 2,000 (Art 5(2)(a)) |
| **Taxed** | Otherwise: VAT on the value times the share of input tax you deducted (KSA Art 15(8)); in the UAE only on the output tax past AED 2,000 |

A taxed one drafts the invoice that states its output tax, which you issue
and **keep**: it is not given to anyone (ZATCA Guideline for Tax Invoicing,
Note 3; UAE Decree-Law Art 65(2)). In Saudi Arabia it is a simplified
invoice flagged as a nominal supply (KSA-2 position 4); in the UAE a tax
invoice flagged as a deemed supply (`01000000`), naming the recipient or
your own company, at your address and emirate. Once issued, its VAT counts
in the return with your standard-rated sales (Saudi box 1; UAE box 1 of
your emirate).

Examples: a SAR 150 gift to Ahmed is relieved; a second of SAR 100 to him
in the same year is taxed, 100 + VAT 15. A SAR 3,000 laptop taken home on
which half the input tax was deducted: VAT 15% on 1,500 = 225.

- What the texts at hand do not give, and the platform does not do: the box
  numbers of either return form for deemed supplies (Saudi Arabia asks for
  their total, IR Art 59(2)(c)), and the UAE's AED 250,000 for a government
  entity or charity (Art 5(2)(b)).
- Lost, stolen or destroyed goods are not a deemed supply (KSA Art 15(4)):
  do not record them here.

<!-- source: app/Domains/Books/Services/DeemedSupplies.php; app/Domains/Compliance/Countries/SaudiArabia/Rules/SpecialSupplies.php; app/Domains/Compliance/Countries/UnitedArabEmirates/Rules/SpecialSupplies.php -->

## Vouchers

**Books › Vouchers** keeps the gift and shopping vouchers you sell.

- **Selling** one records its code, face value, the day, how it was paid
  and when it expires. It is not a supply - KSA VAT Implementing
  Regulations Art 19(1), UAE Decree-Law Art 7(1) - so **no tax invoice** is
  issued and no VAT is due on the money taken.
- **Using** one pays an issued invoice: give the code and the invoice
  number. It is recorded as that invoice's payment, by voucher, and the
  invoice - the actual sale - carries the VAT. What is left stays on the
  voucher until it is used or expires.

Example: a 500.00 voucher pays 345.00 (300.00 + VAT 45.00) of one invoice;
the 155.00 left pays part of the next.

- A voucher is sold at its face value. A price above it is a supply of
  services for the difference (KSA Art 19(2); UAE Art 7(1), Art 40): sell at
  face value and invoice any fee on its own. One sold for less is not
  offered: in the UAE the difference is a discount on what it later pays for
  (Executive Regulation Art 28(5)).
- The law texts the platform works from draw no line between single- and
  multi-purpose vouchers, so there is one kind: no VAT on the sale, VAT on
  what it pays for.
- An expired or used voucher cannot pay. What is left on an expired one is
  shown as such; how to account for it is for your adviser.

<!-- source: app/Domains/Books/Services/Vouchers.php; app/Domains/Compliance/Countries/SaudiArabia/Rules/SpecialSupplies.php; app/Domains/Compliance/Countries/UnitedArabEmirates/Rules/SpecialSupplies.php -->

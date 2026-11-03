// Plain-language meanings for the short forms and trade terms shown in the admin, so a
// shopkeeper can check any of them with the "i" next to it. `full` is what a short form stands for.
export const glossary = {
  SKU: {
    full: 'Stock Keeping Unit',
    meaning:
      'Your own item code for one exact item you stock, such as APX-1L for the 1 litre tin. Optional, and each code must be different.',
  },
  MRP: {
    full: 'Maximum Retail Price',
    meaning:
      'The highest price printed on the pack. You may sell for less but never for more, so it cannot be below your selling price.',
  },
  HSN: {
    full: 'Harmonized System of Nomenclature',
    meaning:
      'The government code for the type of goods, such as 3209 for paints. It is printed on GST bills.',
  },
  GST: {
    full: 'Goods and Services Tax',
    meaning:
      'The tax charged when you sell. Enter the rate that applies to this product, such as 18. Leave it blank if no GST applies.',
  },
  'Location code': {
    full: null,
    meaning:
      'Where the item is kept, written short: SHOP or GODOWN, then the floor (GF = ground floor, 1F = 1st floor), then R and the rack, then S and the shelf. SHOP-GF-R3-S2 means shop, ground floor, rack 3, shelf 2.',
  },
  Slug: {
    full: null,
    meaning:
      'The name written for web links, in small letters with dashes, such as asian-paints. Leave it blank and it is made from the name.',
  },
  UPI: {
    full: 'Unified Payments Interface',
    meaning:
      'A payment from a phone app such as Google Pay, PhonePe or Paytm, made by scanning your QR code or paying to your UPI ID.',
  },
  Due: {
    full: 'Udhaar',
    meaning:
      'The part of a bill the customer has not paid yet. It stays on their account until they pay it, and their oldest bill is cleared first.',
  },
  'Bill number': {
    full: null,
    meaning:
      'Every bill gets the next number for the year. INV-2026-0001 is the first bill of 2026; INV is short for invoice.',
  },
  'Transaction number': {
    full: null,
    meaning:
      'The reference number the payment app or bank shows for a UPI or bank payment, usually 12 digits. Optional, but it helps you match the payment in your bank statement.',
  },
  'Purchase price': {
    full: null,
    meaning:
      'What one unit cost you to buy. When new stock comes in at a different price, it becomes the average cost: 10 pc at ₹280 and then 20 pc at ₹310 makes 30 pc at ₹300.',
  },
  'Average cost': {
    full: null,
    meaning:
      'The purchase price after this delivery: the old stock and the new stock averaged by quantity. 10 pc at ₹280 and 20 pc at ₹310 makes 30 pc at ₹300.',
  },
  Challan: {
    full: 'Delivery challan',
    meaning:
      'The slip that comes with goods from a supplier, listing what was sent. Its number, or the supplier’s bill number, helps you find this delivery later.',
  },
  'List price': {
    full: null,
    meaning:
      'The selling price saved on the product. You can charge a different price on this bill; the product’s own price stays the same.',
  },
}

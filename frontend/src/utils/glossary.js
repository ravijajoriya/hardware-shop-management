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
  Slug: {
    full: null,
    meaning:
      'The name written for web links, in small letters with dashes, such as asian-paints. Leave it blank and it is made from the name.',
  },
}

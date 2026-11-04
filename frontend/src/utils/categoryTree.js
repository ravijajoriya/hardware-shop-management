// How to draw the category tree on a phone. For each category, in tree order:
// - last: no later sibling follows it, so its line to the parent stops at its own row;
// - lines: for each level above it (1 … depth − 1), whether that ancestor's line runs on past
//   this row, because the ancestor has later siblings;
// - hasChildren: subcategories follow it, so a short line leads down from it to them;
// - groupStart and groupEnd: it opens or closes the card of a top-level category;
// - total: products filed under it and under everything beneath it, as the Products filter
//   counts a category.
export function treeRows(tree) {
  const last = tree.map((category, index) => {
    for (let i = index + 1; i < tree.length; i++) {
      if (tree[i].depth < category.depth) {
        return true
      }

      if (tree[i].depth === category.depth) {
        return false
      }
    }

    return true
  })

  const ancestors = []

  return tree.map((category, index) => {
    ancestors[category.depth] = index

    let total = category.products_count ?? 0

    for (let i = index + 1; i < tree.length && tree[i].depth > category.depth; i++) {
      total += tree[i].products_count ?? 0
    }

    return {
      ...category,
      last: last[index],
      lines: Array.from(
        { length: Math.max(category.depth - 1, 0) },
        (_, i) => !last[ancestors[i + 1]],
      ),
      hasChildren: tree[index + 1]?.depth > category.depth,
      groupStart: category.depth === 0,
      groupEnd: index === tree.length - 1 || tree[index + 1].depth === 0,
      total,
    }
  })
}

import { describe, it, expect } from 'vitest'

import { treeRows } from '../categoryTree'

// Electronics
// ├─ Fridge (2)
// ├─ Headphones (1)
// │  └─ Earphones (3)
// └─ Washing Machine (1)
// Tools (5)
const tree = [
  { id: 1, name: 'Electronics', depth: 0, products_count: 0 },
  { id: 2, name: 'Fridge', depth: 1, products_count: 2 },
  { id: 3, name: 'Headphones', depth: 1, products_count: 1 },
  { id: 4, name: 'Earphones', depth: 2, products_count: 3 },
  { id: 5, name: 'Washing Machine', depth: 1, products_count: 1 },
  { id: 6, name: 'Tools', depth: 0, products_count: 5 },
]

const pick = (rows, keys) =>
  rows.map((row) => Object.fromEntries(keys.map((key) => [key, row[key]])))

describe('treeRows', () => {
  it('counts the products under each category with everything beneath it', () => {
    expect(treeRows(tree).map((row) => row.total)).toEqual([7, 2, 4, 3, 1, 5])
  })

  it('knows which lines to draw for each row', () => {
    expect(pick(treeRows(tree), ['name', 'last', 'lines', 'hasChildren'])).toEqual([
      { name: 'Electronics', last: false, lines: [], hasChildren: true },
      { name: 'Fridge', last: false, lines: [], hasChildren: false },
      { name: 'Headphones', last: false, lines: [], hasChildren: true },
      // Headphones has a later sibling, so its line carries on past Earphones.
      { name: 'Earphones', last: true, lines: [true], hasChildren: false },
      { name: 'Washing Machine', last: true, lines: [], hasChildren: false },
      { name: 'Tools', last: true, lines: [], hasChildren: false },
    ])
  })

  it('marks where each top-level card opens and closes', () => {
    expect(pick(treeRows(tree), ['groupStart', 'groupEnd'])).toEqual([
      { groupStart: true, groupEnd: false },
      { groupStart: false, groupEnd: false },
      { groupStart: false, groupEnd: false },
      { groupStart: false, groupEnd: false },
      { groupStart: false, groupEnd: true },
      { groupStart: true, groupEnd: true },
    ])
  })

  it('stops an ancestor line after its last child', () => {
    const deep = [
      { id: 1, name: 'A', depth: 0 },
      { id: 2, name: 'B', depth: 1 },
      { id: 3, name: 'C', depth: 2 },
      { id: 4, name: 'D', depth: 3 },
    ]

    expect(treeRows(deep).map((row) => row.lines)).toEqual([[], [], [false], [false, false]])
    expect(treeRows(deep).map((row) => row.total)).toEqual([0, 0, 0, 0])
  })
})

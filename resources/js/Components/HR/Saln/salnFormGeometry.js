/**
 * Where entries go on the 2025 SALN Form (CSC Resolution No. 2500632,
 * updated 3 February 2026), in points from the page's top-left corner
 * (8.5 × 13 in = 612 × 936 pt). Measured from Word's own rendering of the
 * CSC Word files in docs/reference/saln, the same rendering the page
 * backgrounds in public/images/saln are made from.
 *
 * Lines are the y of a ruled blank; rows list the y of each table rule,
 * top to bottom (row i lies between rows[i] and rows[i + 1]); cols list the
 * x of each column rule, left to right. Boxes are [x0, y0, x1, y1].
 */

const REAL_COLS = [27.9, 95.4, 171.9, 284.4, 342.9, 419.4, 455.4, 504.9, 590.4];
const PERSONAL_COLS = [27.9, 410.4, 504.9, 590.4];
const LIABILITY_COLS = [25.9, 239.4, 473.4, 590.4];
const BUSINESS_COLS = [32.4, 164.7, 311.4, 458.1, 590.4];
const RELATIVE_COLS = [32.4, 180.9, 288.9, 374.4, 590.4];

export const PAGE = { width: 612, height: 936 };

export const FORM_PAGE_1 = {
    image: '/images/saln/2025-saln-form-p1.png',
    compliance: {
        line: 113.0,
        entry: [148.0, 244.8],
        annual: [398.3, 428.7],
        exit: [485.1, 577.8],
        boxes: { entry: [26.5, 101.7, 32.7, 108.1], annual: [246.1, 101.7, 252.4, 108.1], exit: [430.2, 101.7, 436.4, 108.1] },
    },
    // Family name, first name, M.I. blanks, then the right-hand column.
    nameCols: [95.2, 177.6, 280.25, 325.2],
    right: [437.5, 589.9],
    declarant: { name: 148.1, position: 148.1, agency: 159.4, address: [170.8, 182.1] },
    spouse: { name: 193.4, position: 193.4, agency: 204.7, address: [215.9] },
    filing: { joint: [55.3, 261.7, 61.6, 268.0], separate: [163.4, 261.7, 169.6, 268.0], notApplicable: [271.3, 261.7, 277.7, 268.0] },
    marriages: { lines: [307.2, 319.9], x: [46.5, 321.0], notApplicable: [363.6, 297.6, 369.9, 303.9] },
    children: { lines: [395.9, 408.2, 420.5], name: [28.0, 429.1], age: [442.3, 570.9] },
    real: { rows: [555.7, 583.1, 610.8, 638.4, 665.9], cols: REAL_COLS, subtotal: { x: [504.9, 590.4], line: 680.6 } },
    personal: {
        rows: [724.6, 739.2, 754.0, 768.9, 783.7, 798.7, 813.5],
        cols: PERSONAL_COLS,
        subtotal: { x: [504.9, 590.4], line: 828.6 },
        total: { x: [504.9, 590.4], line: 843.4 },
    },
    pageOf: { x: [329.2, 347.1], line: 886.0 },
};

export const FORM_PAGE_2 = {
    image: '/images/saln/2025-saln-form-p2.png',
    liabilities: {
        rows: [47.9, 61.7, 75.9, 89.9, 104.0],
        cols: LIABILITY_COLS,
        total: { x: [473.4, 590.4], line: 117.7 },
        netWorth: { x: [473.4, 590.4], line: 135.0 },
    },
    business: { none: [153.5, 191.0, 161.0, 198.0], rows: [239.3, 255.3, 271.3, 287.3], cols: BUSINESS_COLS },
    relatives: { none: [156.0, 336.5, 163.0, 343.5], rows: [363.9, 377.5, 391.3, 405.0, 418.8, 432.4], cols: RELATIVE_COLS },
    governmentId: { x: [149.5, 293.3], type: 684.9, number: 695.6, issued: 706.0 },
    // The second "Signature of Declarant" block: left for the spouse to fill in by hand.
    secondDeclarant: { signature: { x: [329.4, 577.1], line: 654.5 }, idX: [433.1, 577.1] },
    officer: { x: [301.4, 499.7], line: 781.8, title: [795.0, 804.5] },
    pageOf: { x: [329.2, 347.1], line: 886.0 },
};

/** AS-1 and AS-2 share their tables' columns; AS-2 sits a little higher. */
export const AS1 = {
    image: '/images/saln/2025-saln-as1-p1.png',
    asOf: { x: [267.6, 385.1], line: 107.3 },
    nameCols: [94.6, 177.1, 279.9, 324.8],
    right: [433.1, 585.9],
    name: 146.6,
    agency: 157.9,
    real: { rows: [292.3, 333.4, 374.2, 415.5, 456.8], cols: REAL_COLS, subtotal: { x: [468.9, 590.4], line: 471.0 } },
    personal: {
        rows: [522.7, 537.4, 552.2, 567.2, 582.0],
        cols: PERSONAL_COLS,
        subtotal: { x: [504.9, 590.4], line: 596.9 },
        total: { x: [504.9, 590.4], line: 611.5 },
    },
    liabilities: { rows: [655.5, 669.3, 683.5, 697.5, 711.5], cols: LIABILITY_COLS, total: { x: [473.4, 590.4], line: 725.5 } },
    business: { rows: [804.6, 820.6, 836.7, 852.5], cols: BUSINESS_COLS },
    page: { x: [294.1, 312.7], line: 886.0 },
    of: { x: [331.0, 353.0], line: 886.0 },
};

export const AS2 = {
    image: '/images/saln/2025-saln-as2-p1.png',
    asOf: { x: [267.6, 385.1], line: 107.3 },
    nameCols: [94.6, 177.1, 279.9, 324.8],
    right: [433.1, 585.9],
    name: 142.7,
    agency: 154.1,
    real: { rows: [288.7, 329.3, 370.6, 411.7, 453.0], cols: REAL_COLS },
    personal: { rows: [518.6, 533.0, 547.8, 562.8, 577.5], cols: PERSONAL_COLS },
    liabilities: { rows: [634.2, 648.0, 662.0, 676.0, 690.2], cols: LIABILITY_COLS },
    business: { rows: [780.8, 797.0, 812.9, 829.0], cols: BUSINESS_COLS },
    page: { x: [294.1, 312.7], line: 874.5 },
    of: { x: [331.0, 353.0], line: 874.5 },
};

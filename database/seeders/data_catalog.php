<?php

/**
 * Port of the frontend's data/categories.ts + data/images.ts + data/products.ts.
 * Kept as plain data so it can be compared against the TypeScript source.
 */
return [
    'image_ids' => ['photo-1507679799987-c73779587ccf', 'photo-1617137968427-85924c800a22', 'photo-1488161628813-04466f872be2', 'photo-1552374196-1ab2a1c593e8', 'photo-1521572163474-6864f9cf17ab', 'photo-1480455624313-e29b44bbfde1', 'photo-1519085360753-af0119f7cbe7', 'photo-1500648767791-00dcc994a43e', 'photo-1506794778202-cad84cf45f1d', 'photo-1492562080023-ab3db95bfbce'],
    'cat_imgs' => ['t-shirts' => [4, 6, 7, 2], 'shirts' => [2, 8, 5, 0], 'jeans' => [3, 5, 9, 4], 'trousers' => [5, 3, 8, 0], 'jackets' => [1, 9, 6, 7], 'overshirts' => [8, 2, 9, 5], 'shackets' => [9, 8, 1, 3], 'blazers' => [0, 2, 5, 8], 'hoodies' => [6, 7, 4, 9], 'sweatshirts' => [7, 6, 2, 4]],
    // [slug, label, group, blurb]
    'subcategories' => [
        ['t-shirts', 'T-Shirts', 'topwear', 'Elevated basics designed for everyday movement.'],
        ['shirts', 'Shirts', 'topwear', 'Modern shirts designed for everyday movement.'],
        ['jeans', 'Jeans', 'bottomwear', 'Considered denim, cut for the contemporary man.'],
        ['trousers', 'Trousers', 'bottomwear', 'Tailored lines, relaxed comfort.'],
        ['jackets', 'Jackets', 'outerwear', 'Outer layers with sharp structure.'],
        ['overshirts', 'Overshirts', 'outerwear', 'The layer between a shirt and a jacket.'],
        ['shackets', 'Shackets', 'outerwear', 'Shirt-jacket hybrids for transitional days.'],
        ['blazers', 'Blazers', 'outerwear', 'Unstructured tailoring, modern proportions.'],
        ['hoodies', 'Hoodies', 'outerwear', 'Heavyweight comfort, refined.'],
        ['sweatshirts', 'Sweatshirts', 'outerwear', 'Soft, structured everyday knits.'],
    ],
    'groups' => [['topwear', 'Topwear'], ['bottomwear', 'Bottomwear'], ['outerwear', 'Outerwear']],
    'colors' => [['Black', '#111111'], ['Off White', '#EDE9E0'], ['Navy', '#1F2A44'], ['Olive', '#5B6040'], ['Stone', '#B9B2A3']],
    'sizes' => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
    'prices' => [799, 999, 1299, 1499, 1799, 2199, 2499, 2999, 3499, 4999],
    'patterns' => ['Solid', 'Solid', 'Striped', 'Checked', 'Textured'],
    'sku_codes' => ['t-shirts' => 'TSH', 'shirts' => 'SHT', 'jeans' => 'JNS', 'trousers' => 'TRS', 'jackets' => 'JKT', 'overshirts' => 'OVS', 'shackets' => 'SHK', 'blazers' => 'BLZ', 'hoodies' => 'HOD', 'sweatshirts' => 'SWT'],
    // slug => [names, fabrics, fits]
    'source' => [
        't-shirts' => [['Essential Crew Tee', 'Heavyweight Pocket Tee', 'Boxy Oversized Tee', 'Henley Slub Tee'], ['Cotton', 'Cotton Jersey'], ['Regular', 'Oversized']],
        'shirts' => [['Oxford Button-Down', 'Linen Resort Shirt', 'Mandarin Collar Shirt', 'Striped Poplin Shirt'], ['Linen', 'Cotton Poplin', 'Oxford Cotton'], ['Slim', 'Regular']],
        'jeans' => [['Tapered Indigo Jean', 'Straight Fit Jean', 'Washed Black Jean'], ['Stretch Denim', 'Rigid Denim'], ['Tapered', 'Straight', 'Relaxed']],
        'trousers' => [['Pleated Chino', 'Tailored Wool Trouser', 'Cargo Utility Pant'], ['Cotton Twill', 'Linen Blend', 'Wool Blend'], ['Tapered', 'Relaxed']],
        'jackets' => [['Harrington Jacket', 'Quilted Liner', 'Bomber Jacket'], ['Nylon', 'Suede', 'Cotton Canvas'], ['Regular', 'Boxy']],
        'overshirts' => [['Twill Overshirt', 'Flannel Overshirt', 'Corduroy Overshirt'], ['Cotton Twill', 'Flannel', 'Corduroy'], ['Regular', 'Boxy']],
        'shackets' => [['Wool Blend Shacket', 'Waxed Cotton Shacket', 'Utility Shacket'], ['Wool Blend', 'Cotton Canvas'], ['Regular', 'Relaxed']],
        'blazers' => [['Unstructured Blazer', 'Linen Summer Blazer', 'Textured Wool Blazer'], ['Linen', 'Wool Blend'], ['Slim', 'Regular']],
        'hoodies' => [['Heavyweight Hoodie', 'Zip-Through Hoodie', 'Washed Fleece Hoodie'], ['Cotton Fleece', 'Cotton'], ['Regular', 'Oversized']],
        'sweatshirts' => [['Classic Crew Sweatshirt', 'Quarter-Zip Sweatshirt', 'Waffle Knit Sweatshirt'], ['Cotton Fleece', 'Waffle Knit'], ['Regular', 'Relaxed']],
    ],
];

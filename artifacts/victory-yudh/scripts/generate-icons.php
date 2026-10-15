<?php
declare(strict_types=1);

$output = dirname(__DIR__) . '/public/assets';
if (!extension_loaded('gd') || !is_dir($output)) {
    fwrite(STDERR, "PHP GD and public/assets are required to generate install icons.\n");
    exit(1);
}

foreach ([192, 512] as $size) {
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, true);
    imagesavealpha($image, true);
    $navy = imagecolorallocate($image, 8, 9, 22);
    $purple = imagecolorallocate($image, 137, 73, 255);
    $deepPurple = imagecolorallocate($image, 63, 35, 130);
    $white = imagecolorallocate($image, 255, 255, 255);
    $gold = imagecolorallocate($image, 255, 205, 90);
    imagefill($image, 0, 0, $navy);
    $shield = [
        (int)($size * .50), (int)($size * .13),
        (int)($size * .82), (int)($size * .25),
        (int)($size * .77), (int)($size * .66),
        (int)($size * .50), (int)($size * .86),
        (int)($size * .23), (int)($size * .66),
        (int)($size * .18), (int)($size * .25),
    ];
    imagefilledpolygon($image, $shield, $purple);
    $inner = [
        (int)($size * .50), (int)($size * .21),
        (int)($size * .73), (int)($size * .30),
        (int)($size * .69), (int)($size * .61),
        (int)($size * .50), (int)($size * .76),
        (int)($size * .31), (int)($size * .61),
        (int)($size * .27), (int)($size * .30),
    ];
    imagefilledpolygon($image, $inner, $deepPurple);
    $letter = [
        (int)($size * .31), (int)($size * .31),
        (int)($size * .41), (int)($size * .31),
        (int)($size * .50), (int)($size * .54),
        (int)($size * .59), (int)($size * .31),
        (int)($size * .69), (int)($size * .31),
        (int)($size * .53), (int)($size * .68),
        (int)($size * .47), (int)($size * .68),
    ];
    imagefilledpolygon($image, $letter, $white);
    imagefilledrectangle($image, (int)($size * .38), (int)($size * .74), (int)($size * .62), (int)($size * .77), $gold);
    imagepng($image, $output . '/icon-' . $size . '.png', 9);
    imagedestroy($image);
}

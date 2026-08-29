<?php

namespace App\Http\Controllers;

use GdImage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use RuntimeException;

class LinkPreviewController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $title = Str::limit($request->string('title')->trim()->value() ?: 'DigitoFina', 72, '');
        $description = Str::limit(
            $request->string('description')->trim()->value() ?: 'Mobile-money collections and financial management for loan businesses, SACCOs and investment groups.',
            128,
            '',
        );
        $image = imagecreatetruecolor(1200, 630);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('Unable to create the link preview image.');
        }

        $this->drawBackground($image);
        $this->drawContent($image, $title, $description);

        ob_start();
        imagepng($image, null, 7);
        $png = ob_get_clean();
        imagedestroy($image);

        if (! is_string($png)) {
            throw new RuntimeException('Unable to encode the link preview image.');
        }

        return response($png, Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="digitofina-link-preview.png"',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function drawBackground(GdImage $image): void
    {
        $source = imagecreatefrompng(public_path('marketing/digitofina-flyers/landscape-repayment-background.png'));

        if ($source instanceof GdImage) {
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $cropHeight = (int) round($sourceWidth * 630 / 1200);
            $sourceY = max(0, (int) floor(($sourceHeight - $cropHeight) / 2));

            imagecopyresampled($image, $source, 0, 0, 0, $sourceY, 1200, 630, $sourceWidth, $cropHeight);
            imagedestroy($source);
        } else {
            imagefill($image, 0, 0, imagecolorallocate($image, 59, 7, 100));
        }

        imagealphablending($image, true);
        imagefilledrectangle($image, 0, 0, 1200, 630, imagecolorallocatealpha($image, 38, 7, 66, 55));
        imagefilledrectangle($image, 570, 0, 1200, 630, imagecolorallocatealpha($image, 20, 5, 38, 12));
        imagefilledrectangle($image, 622, 72, 632, 558, imagecolorallocate($image, 252, 211, 77));
    }

    private function drawContent(GdImage $image, string $title, string $description): void
    {
        $white = imagecolorallocate($image, 255, 255, 255);
        $violet = imagecolorallocate($image, 233, 213, 255);
        $amber = imagecolorallocate($image, 252, 211, 77);
        $boldFont = $this->fontPath(true);
        $regularFont = $this->fontPath(false);

        $this->drawText($image, 'DIGITOFINA', 662, 116, 22, $amber, $boldFont);
        $this->drawText($image, 'MICROFINANCE MANAGEMENT SYSTEM', 662, 151, 13, $violet, $boldFont);
        $this->drawWrappedText($image, $title, 662, 238, 37, 465, 48, 4, $white, $boldFont);
        $this->drawWrappedText($image, $description, 662, 446, 18, 465, 27, 3, $violet, $regularFont);
        $this->drawText($image, 'Loans  •  Savings  •  Mobile Money', 662, 568, 15, $amber, $boldFont);
    }

    private function fontPath(bool $bold): ?string
    {
        $candidates = $bold
            ? ['/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/System/Library/Fonts/Supplemental/Arial Bold.ttf', '/Library/Fonts/Arial Bold.ttf']
            : ['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/System/Library/Fonts/Supplemental/Arial.ttf', '/Library/Fonts/Arial.ttf'];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function drawText(GdImage $image, string $text, int $x, int $y, int $size, int $color, ?string $font): void
    {
        if ($font !== null) {
            imagettftext($image, $size, 0, $x, $y, $color, $font, $text);

            return;
        }

        imagestring($image, 5, $x, $y - 16, $text, $color);
    }

    private function drawWrappedText(
        GdImage $image,
        string $text,
        int $x,
        int $y,
        int $size,
        int $maxWidth,
        int $lineHeight,
        int $maxLines,
        int $color,
        ?string $font,
    ): void {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $line = '';

        foreach ($words as $word) {
            $candidate = trim("{$line} {$word}");

            if ($line !== '' && $this->textWidth($candidate, $size, $font) > $maxWidth) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        foreach (array_slice($lines, 0, $maxLines) as $index => $wrappedLine) {
            $this->drawText($image, $wrappedLine, $x, $y + ($index * $lineHeight), $size, $color, $font);
        }
    }

    private function textWidth(string $text, int $size, ?string $font): int
    {
        if ($font === null) {
            return strlen($text) * imagefontwidth(5);
        }

        $box = imagettfbbox($size, 0, $font, $text);

        return is_array($box) ? abs($box[2] - $box[0]) : 0;
    }
}

<?php

namespace App\Console\Commands;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use League\CommonMark\CommonMarkConverter;

class ExportConversations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'conversations:export
        {file : Absolute or relative path to conversations.json}
        {--output= : Directory where markdown files should be written (defaults to storage/app/conversations)}
        {--assets= : Directory that contains exported attachments (defaults to the JSON file\'s directory)}
        {--mode= : Export mode: auto exports everything, manual asks before each conversation}
        {--asset-mode= : Attachment strategy: copy (default) or reference original files}
        {--thumbnail-width=512 : Maximum width in pixels for generated thumbnails (0 disables thumbnails)}
        {--format=markdown : Export format: markdown, pdf, or both}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export chatgpt conversations.json to individual markdown transcripts';

    private ?ImageManager $imageManager = null;
    private ?CommonMarkConverter $markdownConverter = null;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $jsonPath = $this->normalizePath($this->argument('file'));

        if (empty($jsonPath) || ! is_file($jsonPath)) {
            $this->error('The conversations file could not be found.');

            return self::FAILURE;
        }

        $outputDirectory = $this->normalizeDirectory(
            $this->option('output') ?: storage_path('app/conversations')
        );

        if (! File::exists($outputDirectory)) {
            File::makeDirectory($outputDirectory, 0o755, true);
        } elseif (! File::isDirectory($outputDirectory)) {
            $this->error('The output path points to an existing file. Please supply a directory.');

            return self::FAILURE;
        }

        $assetDirectory = $this->normalizeDirectory(
            $this->option('assets') ?: dirname($jsonPath)
        );

        if (! File::isDirectory($assetDirectory)) {
            $this->warn('Attachment directory not found. Image references will point at the original asset pointers.');
        }

        $assetMode = $this->normalizeAssetMode($this->option('asset-mode'));
        $thumbnailWidth = $this->normalizeThumbnailWidth($this->option('thumbnail-width'));
        $formats = $this->resolveFormats($this->option('format'));
        $includeMarkdown = in_array('markdown', $formats, true);
        $includePdf = in_array('pdf', $formats, true);

        $mode = strtolower((string) $this->option('mode'));
        if (! in_array($mode, ['auto', 'manual'], true)) {
            $choice = $this->choice(
                'How would you like to export the conversations?',
                [
                    'Automatic (export every conversation)',
                    'Manual (ask before exporting each conversation)',
                ],
                0
            );

            $mode = str_starts_with(Str::lower($choice), 'manual') ? 'manual' : 'auto';
        }

        $items = Items::fromFile($jsonPath, ['decoder' => new ExtJsonDecoder(true)]);
        $total = 0;
        $exported = 0;

        foreach ($items as $conversation) {
            ++$total;
            $title = $this->conversationTitle($conversation, $total);

            if ($mode === 'manual' && ! $this->confirm("Export \"{$title}\"?", true)) {
                $this->line("Skipping {$title}");
                continue;
            }

            $targetPath = $this->generateTargetPath($outputDirectory, $title, $total);
            $markdown = $this->buildMarkdown(
                $conversation,
                $assetDirectory,
                $targetPath,
                $assetMode,
                $thumbnailWidth
            );

            $exportPaths = [];

            if ($includeMarkdown) {
                File::put($targetPath, $markdown);
                $exportPaths[] = $targetPath;
            }

            if ($includePdf) {
                $pdfPath = $this->pdfPathFor($targetPath);

                if ($this->writePdf($markdown, $targetPath, $pdfPath)) {
                    $exportPaths[] = $pdfPath;
                }
            }

            ++$exported;

            $destinationSummary = ! empty($exportPaths)
                ? implode(', ', $exportPaths)
                : $targetPath;

            $this->info("Exported {$title} -> {$destinationSummary}");
        }

        if ($total === 0) {
            $this->warn('No conversations discovered in the provided file.');
        } else {
            $this->info("Finished exporting {$exported} of {$total} conversations into {$outputDirectory}");
        }

        return self::SUCCESS;
    }

    private function normalizePath(?string $path): string
    {
        if (empty($path)) {
            return '';
        }

        $absolute = $this->isAbsolutePath($path) ? $path : base_path($path);

        return realpath($absolute) ?: $absolute;
    }

    private function normalizeDirectory(?string $path): string
    {
        $normalized = $this->normalizePath($path);

        return rtrim($normalized ?: base_path(), DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || (strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':');
    }

    private function conversationTitle(mixed $conversation, int $sequence): string
    {
        $rawTitle = is_array($conversation) ? (string) ($conversation['title'] ?? '') : '';

        $title = trim($rawTitle);

        return $title !== '' ? $title : "Conversation {$sequence}";
    }

    private function generateTargetPath(string $directory, string $title, int $sequence): string
    {
        $slug = Str::slug($title);

        if ($slug === '') {
            $slug = 'conversation-' . $sequence;
        }

        $slug = Str::limit($slug, 120, '');
        $candidate = $directory . DIRECTORY_SEPARATOR . "{$slug}.md";
        $suffix = 1;

        while (File::exists($candidate)) {
            $candidate = $directory . DIRECTORY_SEPARATOR . "{$slug}-{$suffix}.md";
            ++$suffix;
        }

        return $candidate;
    }

    private function buildMarkdown(
        array $conversation,
        string $assetDirectory,
        string $targetPath,
        string $assetMode,
        int $thumbnailWidth
    ): string
    {
        $sections = [];
        $title = $this->conversationTitle($conversation, 0);
        $sections[] = '# ' . $title;

        $meta = $this->buildMetadata($conversation);

        if (! empty($meta)) {
            $sections[] = implode("\n", $meta);
        }

        $messages = $this->collectMessages($conversation);

        if (empty($messages)) {
            $sections[] = '_No transcript was available for this conversation._';

            return implode("\n\n", $sections);
        }

        $assetCopyDirectory = null;
        $copiedAssets = [];

        $thumbnailCache = [];

        foreach ($messages as $message) {
            $sections[] = $this->formatMessage(
                $message,
                $assetDirectory,
                $targetPath,
                $assetMode,
                $thumbnailWidth,
                $assetCopyDirectory,
                $copiedAssets,
                $thumbnailCache
            );
        }

        return implode("\n\n", array_filter($sections, static fn ($section) => $section !== ''));
    }

    private function buildMetadata(array $conversation): array
    {
        $metadata = [];

        $identifier = $conversation['conversation_id'] ?? $conversation['id'] ?? null;
        if ($identifier) {
            $metadata[] = "- **Conversation ID:** {$identifier}";
        }

        if (isset($conversation['create_time'])) {
            $metadata[] = '- **Created:** ' . $this->formatTimestamp($conversation['create_time']);
        }

        if (isset($conversation['update_time'])) {
            $metadata[] = '- **Updated:** ' . $this->formatTimestamp($conversation['update_time']);
        }

        $model = $conversation['default_model_slug'] ?? null;
        if ($model) {
            $metadata[] = "- **Model:** {$model}";
        }

        return $metadata;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectMessages(array $conversation): array
    {
        $mapping = $conversation['mapping'] ?? [];
        $currentNodeId = $conversation['current_node'] ?? null;

        if (! is_array($mapping) || empty($mapping)) {
            return [];
        }

        $orderedNodes = [];
        $visited = 0;
        $nodeId = $currentNodeId;

        while ($nodeId && isset($mapping[$nodeId])) {
            array_unshift($orderedNodes, $mapping[$nodeId]);
            $nodeId = $mapping[$nodeId]['parent'] ?? null;

            if (++$visited > count($mapping) + 2) {
                break;
            }
        }

        if (empty($orderedNodes)) {
            // fall back to chronological order if no linked list could be built
            $orderedNodes = array_values($mapping);
            usort($orderedNodes, static function ($a, $b) {
                $timeA = $a['message']['create_time'] ?? 0;
                $timeB = $b['message']['create_time'] ?? 0;

                return $timeA <=> $timeB;
            });
        }

        $messages = [];
        foreach ($orderedNodes as $node) {
            $message = $node['message'] ?? null;

            if ($message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function formatMessage(
        array $message,
        string $assetDirectory,
        string $targetPath,
        string $assetMode,
        int $thumbnailWidth,
        ?string &$assetCopyDirectory,
        array &$copiedAssets,
        array &$thumbnailCache
    ): string
    {
        $parts = [];
        $author = ucfirst($message['author']['role'] ?? 'unknown');
        $timestamp = $this->formatTimestamp($message['create_time'] ?? null);
        $parts[] = $timestamp ? "## {$author} ({$timestamp})" : "## {$author}";

        $body = $this->renderContent(
            $message['content'] ?? null,
            $assetDirectory,
            $targetPath,
            $assetMode,
            $thumbnailWidth,
            $assetCopyDirectory,
            $copiedAssets,
            $thumbnailCache
        );
        $parts[] = $body !== '' ? $body : '_No visible content in this message._';

        return implode("\n\n", $parts);
    }

    private function formatTimestamp(mixed $value): ?string
    {
        if (is_numeric($value)) {
            try {
                return Carbon::createFromTimestamp($value)->toDateTimeString();
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    private function renderContent(
        ?array $content,
        string $assetDirectory,
        string $targetPath,
        string $assetMode,
        int $thumbnailWidth,
        ?string &$assetCopyDirectory,
        array &$copiedAssets,
        array &$thumbnailCache
    ): string
    {
        if (empty($content)) {
            return '';
        }

        $parts = $content['parts'] ?? [];
        if (! is_array($parts)) {
            return '';
        }

        $fragments = [];

        foreach ($parts as $part) {
            if (is_string($part)) {
                $fragments[] = trim($part);
                continue;
            }

            if (! is_array($part)) {
                continue;
            }

            $fragments[] = $this->renderStructuredPart(
                $part,
                $assetDirectory,
                $targetPath,
                $assetMode,
                $thumbnailWidth,
                $assetCopyDirectory,
                $copiedAssets,
                $thumbnailCache
            );
        }

        if (empty(array_filter($fragments)) && isset($content['text']) && is_string($content['text'])) {
            $fragments[] = trim($content['text']);
        }

        return trim(implode("\n\n", array_filter($fragments, static fn ($chunk) => trim((string) $chunk) !== '')));
    }

    private function renderStructuredPart(
        array $part,
        string $assetDirectory,
        string $targetPath,
        string $assetMode,
        int $thumbnailWidth,
        ?string &$assetCopyDirectory,
        array &$copiedAssets,
        array &$thumbnailCache
    ): string
    {
        return match ($part['content_type'] ?? null) {
            'text' => trim((string) ($part['text'] ?? '')),
            'image_asset_pointer' => $this->renderImagePart(
                $part,
                $assetDirectory,
                $targetPath,
                $assetMode,
                $thumbnailWidth,
                $assetCopyDirectory,
                $copiedAssets,
                $thumbnailCache
            ),
            default => $this->renderFallbackPart($part),
        };
    }

    private function renderFallbackPart(array $part): string
    {
        $type = $part['content_type'] ?? 'data';

        return sprintf('_Unsupported %s part omitted_', $type);
    }

    private function renderImagePart(
        array $part,
        string $assetDirectory,
        string $targetPath,
        string $assetMode,
        int $thumbnailWidth,
        ?string &$assetCopyDirectory,
        array &$copiedAssets,
        array &$thumbnailCache
    ): string
    {
        $pointer = $part['asset_pointer'] ?? null;
        if (! $pointer) {
            return '_Image pointer missing_';
        }

        $resolved = $this->resolveAssetPointer($pointer, $assetDirectory);
        $fullPath = null;

        if ($resolved) {
            if ($assetMode === 'copy') {
                $copied = $this->copyAssetToTranscript(
                    $resolved,
                    $targetPath,
                    $assetCopyDirectory,
                    $copiedAssets
                );
                $fullPath = $copied ?: $resolved;
            } else {
                $fullPath = $resolved;
            }
        }

        $fullDisplay = $fullPath
            ? $this->relativePath($targetPath, $fullPath)
            : $pointer;

        $thumbnailPath = null;

        if ($fullPath && $thumbnailWidth > 0) {
            $thumbnailPath = $this->createThumbnail(
                $fullPath,
                $targetPath,
                $assetCopyDirectory,
                $thumbnailWidth,
                $thumbnailCache
            );
        }

        $fullDisplay = str_replace(' ', '%20', $fullDisplay);

        if ($thumbnailPath) {
            $thumbnailDisplay = $this->relativePath($targetPath, $thumbnailPath);

            return sprintf(
                '[![Image %s](%s)](%s)',
                basename($pointer),
                str_replace(' ', '%20', $thumbnailDisplay),
                $fullDisplay
            );
        }

        return sprintf('![Image %s](%s)', basename($pointer), $fullDisplay);
    }

    private function resolveAssetPointer(string $pointer, string $assetDirectory): ?string
    {
        if (! File::isDirectory($assetDirectory)) {
            return null;
        }

        $basename = basename($pointer);
        $pattern = $assetDirectory . DIRECTORY_SEPARATOR . $basename . '*';
        $matches = glob($pattern);

        return $matches ? ($this->normalizePath($matches[0]) ?: $matches[0]) : null;
    }

    private function copyAssetToTranscript(
        string $source,
        string $targetPath,
        ?string &$assetCopyDirectory,
        array &$copiedAssets
    ): ?string {
        if (isset($copiedAssets[$source]) && File::exists($copiedAssets[$source])) {
            return $copiedAssets[$source];
        }

        $assetCopyDirectory = $this->ensureAssetCopyDirectory($targetPath, $assetCopyDirectory);

        if (! $assetCopyDirectory) {
            return null;
        }

        $destination = $this->uniqueAssetDestination($assetCopyDirectory, basename($source));

        if (! File::copy($source, $destination)) {
            return null;
        }

        $copiedAssets[$source] = $destination;

        return $destination;
    }

    private function createThumbnail(
        string $source,
        string $targetPath,
        ?string &$assetCopyDirectory,
        int $maxWidth,
        array &$thumbnailCache
    ): ?string {
        if ($maxWidth <= 0) {
            return null;
        }

        if (isset($thumbnailCache[$source]) && File::exists($thumbnailCache[$source])) {
            return $thumbnailCache[$source];
        }

        if (! File::exists($source)) {
            return null;
        }

        $assetCopyDirectory = $this->ensureAssetCopyDirectory($targetPath, $assetCopyDirectory);

        if (! $assetCopyDirectory) {
            return null;
        }

        $thumbnailDirectory = $assetCopyDirectory . DIRECTORY_SEPARATOR . 'thumbnails';

        if (! File::exists($thumbnailDirectory)) {
            File::makeDirectory($thumbnailDirectory, 0o755, true);
        }

        $destination = $this->uniqueAssetDestination($thumbnailDirectory, basename($source));

        try {
            $image = $this->imageManager()->read($source)->orient();

            if ($image->width() > $maxWidth) {
                $image->scaleDown($maxWidth);
            }

            $image->save($destination);
        } catch (\Throwable $e) {
            $this->warn("Failed to create thumbnail for {$source}: {$e->getMessage()}");

            return null;
        }

        $thumbnailCache[$source] = $destination;

        return $destination;
    }

    private function ensureAssetCopyDirectory(string $targetPath, ?string $existing): ?string
    {
        if ($existing && File::isDirectory($existing)) {
            return $existing;
        }

        $directory = dirname($targetPath);
        $stem = pathinfo($targetPath, PATHINFO_FILENAME);
        $base = $directory . DIRECTORY_SEPARATOR . $stem . '_assets';
        $candidate = $base;
        $suffix = 1;

        while (File::exists($candidate) && ! File::isDirectory($candidate)) {
            $candidate = $base . '_' . $suffix;
            ++$suffix;
        }

        if (! File::exists($candidate)) {
            File::makeDirectory($candidate, 0o755, true);
        }

        return $candidate;
    }

    private function uniqueAssetDestination(string $directory, string $filename): string
    {
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;

        if (! File::exists($destination)) {
            return $destination;
        }

        $name = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $suffix = 1;

        do {
            $candidate = $extension !== ''
                ? "{$name}-{$suffix}.{$extension}"
                : "{$name}-{$suffix}";
            $destination = $directory . DIRECTORY_SEPARATOR . $candidate;
            ++$suffix;
        } while (File::exists($destination));

        return $destination;
    }

    private function normalizeAssetMode(?string $mode): string
    {
        $normalized = strtolower($mode ?: 'copy');

        if (! in_array($normalized, ['copy', 'reference'], true)) {
            $this->warn("Unknown asset mode \"{$mode}\". Defaulting to copy.");

            return 'copy';
        }

        return $normalized;
    }

    private function resolveFormats(?string $formatOption): array
    {
        $option = strtolower(trim((string) $formatOption));

        if ($option === '') {
            return ['markdown'];
        }

        $tokens = array_filter(array_map('trim', explode(',', $option)));

        if (in_array('both', $tokens, true)) {
            return ['markdown', 'pdf'];
        }

        $valid = [];

        foreach ($tokens as $token) {
            if (in_array($token, ['markdown', 'pdf'], true)) {
                $valid[$token] = true;
            } else {
                $this->warn("Unknown format \"{$token}\" ignored.");
            }
        }

        if (empty($valid)) {
            $this->warn('No valid export formats provided. Defaulting to markdown.');

            return ['markdown'];
        }

        return array_keys($valid);
    }

    private function pdfPathFor(string $markdownPath): string
    {
        $directory = dirname($markdownPath);
        $base = pathinfo($markdownPath, PATHINFO_FILENAME);
        $candidate = $directory . DIRECTORY_SEPARATOR . "{$base}.pdf";
        $suffix = 1;

        while (File::exists($candidate)) {
            $candidate = $directory . DIRECTORY_SEPARATOR . "{$base}-{$suffix}.pdf";
            ++$suffix;
        }

        return $candidate;
    }

    private function normalizeThumbnailWidth(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 512;
        }

        if (! is_numeric($value)) {
            $this->warn('Invalid thumbnail width specified. Using default width of 512px.');

            return 512;
        }

        $width = (int) $value;

        if ($width < 0) {
            $this->warn('Thumbnail width cannot be negative. Thumbnails will be disabled.');

            return 0;
        }

        return $width;
    }

    private function imageManager(): ImageManager
    {
        if (! $this->imageManager) {
            $this->imageManager = new ImageManager(new Driver());
        }

        return $this->imageManager;
    }

    private function markdownConverter(): CommonMarkConverter
    {
        if (! $this->markdownConverter) {
            $this->markdownConverter = new CommonMarkConverter([
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]);
        }

        return $this->markdownConverter;
    }

    private function writePdf(string $markdown, string $markdownPath, string $pdfPath): bool
    {
        $html = $this->convertMarkdownToHtml($markdown, $markdownPath);

        try {
            Pdf::loadHTML($html)
                ->setPaper('a4')
                ->save($pdfPath);
        } catch (\Throwable $e) {
            $this->error("Failed to generate PDF for {$markdownPath}: {$e->getMessage()}");

            return false;
        }

        return true;
    }

    private function convertMarkdownToHtml(string $markdown, string $targetPath): string
    {
        $body = $this->markdownConverter()->convert($markdown)->getContent();
        $body = $this->rewriteLocalLinks($body, $targetPath);
        $styles = <<<'CSS'
body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #1f2933; font-size: 12pt; line-height: 1.5; }
h1, h2, h3, h4 { color: #111827; }
img { max-width: 100%; height: auto; }
pre { background: #f3f4f6; padding: 0.8rem; border-radius: 0.5rem; overflow: auto; }
code { font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace; background: #f3f4f6; padding: 0.1rem 0.3rem; border-radius: 0.4rem; }
blockquote { border-left: 4px solid #d1d5db; margin: 0; padding: 0.2rem 1rem; color: #4b5563; background: #f9fafb; }
table { border-collapse: collapse; width: 100%; }
td, th { border: 1px solid #e5e7eb; padding: 0.5rem; text-align: left; }
CSS;

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Conversation Export</title>
<style>
{$styles}
</style>
</head>
<body>
{$body}
</body>
</html>
HTML;
    }

    private function rewriteLocalLinks(string $html, string $targetPath): string
    {
        if ($html === '') {
            return $html;
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previousLibxml = libxml_use_internal_errors(true);

        try {
            $dom->loadHTML(
                mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'),
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
            );
        } catch (\Throwable $e) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxml);

            return $html;
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxml);

        $baseDir = dirname($targetPath);

        foreach ($dom->getElementsByTagName('img') as $img) {
            $absolute = $this->absoluteLocalLink($img->getAttribute('src'), $baseDir);
            if ($absolute) {
                $img->setAttribute('src', $absolute);
            }
        }

        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $absolute = $this->absoluteLocalLink($anchor->getAttribute('href'), $baseDir);
            if ($absolute) {
                $anchor->setAttribute('href', $absolute);
            }
        }

        return $dom->saveHTML();
    }

    private function absoluteLocalLink(string $path, string $baseDir): ?string
    {
        $path = trim($path);

        if ($path === '' || $path[0] === '#') {
            return null;
        }

        if (preg_match('#^[a-z]+:#i', $path) || str_starts_with($path, '//')) {
            return null;
        }

        $fullPath = $this->isAbsolutePath($path)
            ? (realpath($path) ?: $path)
            : (realpath($baseDir . DIRECTORY_SEPARATOR . $path) ?: null);

        if (! $fullPath || ! File::exists($fullPath)) {
            return null;
        }

        $normalized = DIRECTORY_SEPARATOR === '\\'
            ? str_replace('\\', '/', $fullPath)
            : $fullPath;

        return 'file://' . str_replace(' ', '%20', $normalized);
    }

    private function relativePath(string $fromFile, string $toFile): string
    {
        $from = realpath(dirname($fromFile)) ?: dirname($fromFile);
        $to = realpath($toFile) ?: $toFile;

        $fromParts = $from !== DIRECTORY_SEPARATOR
            ? explode(DIRECTORY_SEPARATOR, trim($from, DIRECTORY_SEPARATOR))
            : [$from];
        $toParts = $to !== DIRECTORY_SEPARATOR
            ? explode(DIRECTORY_SEPARATOR, trim($to, DIRECTORY_SEPARATOR))
            : [$to];

        while ($fromParts && $toParts && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        $ascending = $fromParts ? str_repeat('..' . DIRECTORY_SEPARATOR, count($fromParts)) : '';
        $descending = implode(DIRECTORY_SEPARATOR, $toParts);
        $relative = $ascending . $descending;

        return $relative !== '' ? $relative : basename($toFile);
    }
}

<?php

namespace App\Domain\ShareOut;

/**
 * One line of the share-out pre-flight checklist.
 *
 * A pure value. Each item knows whether it is clear, how many things are still
 * standing in the way, and where the committee goes to deal with them — the screen
 * renders green or red straight off `passed` and links at `href`, so a new check is
 * added by returning one more of these rather than by touching the page.
 */
final readonly class PreflightItem
{
    /**
     * @param  array<int, array<string, mixed>>  $outstanding  the rows still blocking, for the drill-down
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public bool $passed,
        public int $outstandingCount,
        public array $outstanding,
        public string $href,
        public string $verdict,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $outstanding
     */
    public static function clear(string $key, string $label, string $description, string $href, string $verdict, array $outstanding = []): self
    {
        return new self($key, $label, $description, true, count($outstanding), $outstanding, $href, $verdict);
    }

    /**
     * @param  array<int, array<string, mixed>>  $outstanding
     */
    public static function blocked(string $key, string $label, string $description, string $href, string $verdict, array $outstanding): self
    {
        return new self($key, $label, $description, false, count($outstanding), $outstanding, $href, $verdict);
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     passed: bool,
     *     outstanding_count: int,
     *     outstanding: array<int, array<string, mixed>>,
     *     href: string,
     *     verdict: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'passed' => $this->passed,
            'outstanding_count' => $this->outstandingCount,
            'outstanding' => $this->outstanding,
            'href' => $this->href,
            'verdict' => $this->verdict,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Core\Configurator\Parameter;

/**
 * The rules of a structured parameter value (a fence line of several runs, a
 * room outline, …): which value is the default, how raw input is turned into
 * the canonical value, and which editor the form renders for it.
 *
 * The canonical value is a string (JSON), so a configuration stays a flat map
 * of scalars: the URL, the stored request and the option pricing need no
 * special handling.
 */
interface StructuredValue
{
    public function defaultValue(): string;

    /**
     * Turns any input (a JSON string, a decoded array, garbage) into the
     * closest valid canonical value; never rejects.
     */
    public function normalize(mixed $raw): string;

    /**
     * Whether the input already was the value it resolved to (no correction
     * worth telling the customer about).
     */
    public function isSameInput(mixed $raw, string $resolved): bool;

    /**
     * Name of the editor template: components/configurator/editor/_{editor}.html.twig,
     * and its summary in components/configurator/editor/_{editor}_summary.html.twig.
     */
    public function editor(): string;

    /**
     * JSON Schema of the value as a whole, for clients that set it without
     * the editor (WebMCP tools, see docs/configurator/webmcp.md): what each
     * field means, in which unit, within which bounds. normalize() still
     * corrects whatever arrives.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;
}

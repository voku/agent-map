<?php

declare(strict_types=1);

namespace voku\AgentMap\Reference\Template\Smarty;

use voku\AgentMap\Reference\Template\TemplateFact;

/**
 * Everything {@see SmartyEvidenceBuilder} found in one repository: raw template facts, PHP render sites and
 * registrations, the typed references derived from them, and the coverage gaps that keep absence from being proven.
 *
 * Experimental: not part of the Map schema or any plan contract.
 */
final readonly class SmartyEvidenceReport
{
    /**
     * @param list<string> $templates repository-relative template paths
     * @param list<TemplateFact> $facts
     * @param list<SmartyRenderSite> $sites
     * @param list<SmartyPluginRegistration> $registrations
     * @param list<SmartyTypedReference> $references
     * @param list<array{kind: string, path: string, line: int, detail: string}> $gaps
     */
    public function __construct(
        public array $templates,
        public array $facts,
        public array $sites,
        public array $registrations,
        public array $references,
        public array $gaps,
    ) {
    }

    /**
     * Typed references to one class or member. PHP class and method names compare case-insensitively.
     *
     * @return list<SmartyTypedReference>
     */
    public function referencesTo(string $class, ?string $member = null): array
    {
        $class = strtolower(ltrim($class, '\\'));

        return array_values(array_filter(
            $this->references,
            static fn (SmartyTypedReference $reference): bool => strtolower($reference->class) === $class
                && ($member === null || ($reference->member !== null && strtolower($reference->member) === strtolower($member))),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => 'agent-map-smarty-evidence@experimental',
            'templates' => $this->templates,
            'render_sites' => array_map(static fn (SmartyRenderSite $site): array => $site->toArray(), $this->sites),
            'registrations' => array_map(static fn (SmartyPluginRegistration $registration): array => $registration->toArray(), $this->registrations),
            'references' => array_map(static fn (SmartyTypedReference $reference): array => $reference->toArray(), $this->references),
            'gaps' => $this->gaps,
            'facts' => array_map(static fn (TemplateFact $fact): array => $fact->toArray(), $this->facts),
        ];
    }
}

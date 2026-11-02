@props(['signal' => []])

@if(($signal['research_only'] ?? false) === true)
    <p {{ $attributes->class(['text-xs font-semibold text-amber-300']) }}>Paper research — profitability is unverified.</p>
@endif

{{--
    One endpoint of the API reference, folded: its method and path, and when
    opened what it takes and answers, as the OpenAPI description says.
--}}
<x-ui.section closed :id="$operation['id']">
    <x-slot:caption>
        <span class="flex flex-wrap items-center gap-2" dir="ltr">
            <span class="rounded bg-sunken px-2 py-0.5 font-mono text-xs font-semibold text-fg">{{ $operation['method'] }}</span>
            <span class="break-all font-mono text-sm text-fg">{{ $operation['path'] }}</span>
        </span>
    </x-slot:caption>
    <div class="space-y-4 text-sm">
        @if ($operation['description'] !== '')
            <p class="text-muted" dir="ltr">{{ $operation['description'] }}</p>
        @endif
        @if ($operation['security'] !== [])
            <p class="text-xs text-subtle">{{ __('Authentication') }}: <span class="font-mono" dir="ltr">{{ implode(', ', $operation['security']) }}</span></p>
        @endif
        @if ($operation['parameters'] !== [])
            <div>
                <p class="font-medium text-fg">{{ __('Parameters') }}</p>
                <dl class="mt-1 space-y-1" dir="ltr">
                    @foreach ($operation['parameters'] as $parameter)
                        <div>
                            <dt class="font-mono text-xs text-fg">{{ $parameter['name'] }} <span class="text-subtle">({{ $parameter['in'] }}{{ $parameter['required'] ? ', '.__('required') : '' }})</span></dt>
                            @if ($parameter['description'] !== '')
                                <dd class="text-xs text-muted">{{ $parameter['description'] }}</dd>
                            @endif
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif
        @if ($operation['fields'] !== [])
            <div>
                <p class="font-medium text-fg">{{ __('Body (JSON)') }}</p>
                <ul class="mt-1 grid grid-cols-1 gap-x-4 gap-y-0.5 font-mono text-xs sm:grid-cols-2" dir="ltr">
                    @foreach ($operation['fields'] as $field)
                        <li><span class="text-fg">{{ $field['name'] }}</span> <span class="text-subtle">{{ $field['type'] }}{{ $field['required'] ? ', '.__('required') : '' }}</span></li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if ($operation['responses'] !== [])
            <div>
                <p class="font-medium text-fg">{{ __('Answers') }}</p>
                <ul class="mt-1 space-y-0.5 text-xs" dir="ltr">
                    @foreach ($operation['responses'] as $response)
                        <li><span class="font-mono text-fg">{{ $response['code'] }}</span> <span class="text-muted">{{ $response['description'] }}</span></li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-ui.section>

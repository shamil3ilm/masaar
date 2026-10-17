{{-- A UAE organization's received e-invoices, after Invoices (Presentation::navigation). --}}
                <a href="{{ route('portal.received.index') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.received.*') ? 'bg-teal-50 dark:bg-teal-950/40 font-medium text-teal-800 dark:text-teal-200' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-950' }}">
                    <svg class="w-5 h-5 me-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg>
                    {{ __('Received invoices') }}
                </a>

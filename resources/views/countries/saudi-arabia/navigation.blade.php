{{-- ZATCA onboarding, among an administrator's links (Presentation::navigation). --}}
                <a href="{{ route('portal.zatca.show') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.zatca.*') ? 'bg-teal-50 dark:bg-teal-950/40 font-medium text-teal-800 dark:text-teal-200' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-950' }}">
                    <svg class="w-5 h-5 me-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                    {{ __('ZATCA') }}
                </a>

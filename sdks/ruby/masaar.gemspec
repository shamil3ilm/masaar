# frozen_string_literal: true

Gem::Specification.new do |spec|
  spec.name          = 'masaar'
  spec.version       = '1.0.0'
  spec.authors       = ['Masaar']

  spec.summary       = 'ZATCA-compliant e-invoicing API client for Ruby'
  spec.description   = 'Official Ruby SDK for Masaar ZATCA e-invoicing API. ' \
                       'Supports invoice creation, ZATCA compliance, and webhooks.'
  spec.homepage      = 'https://github.com/shamil3ilm/masaar'
  spec.license       = 'MIT'
  spec.required_ruby_version = '>= 2.7.0'

  spec.files         = Dir['lib/**/*', 'README.md', 'LICENSE']
  spec.require_paths = ['lib']

  spec.add_development_dependency 'minitest', '~> 5.0'
  spec.add_development_dependency 'rake', '~> 13.0'
  spec.add_development_dependency 'rubocop', '~> 1.0'
end

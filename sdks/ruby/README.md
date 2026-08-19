# Masaar Ruby SDK

ZATCA-compliant e-invoicing API client for Ruby 2.7+.

## Installation

Add to your Gemfile:

```ruby
gem 'masaar'
```

Then run:

```bash
bundle install
```

Or install directly:

```bash
gem install masaar
```

## Quick Start

```ruby
require 'masaar'

# Initialize client
client = Masaar::Client.new(
  base_url: 'http://localhost:8000',  # Your server URL
  api_key: 'your_api_key',
  api_secret: 'your_api_secret'
)

# Create an invoice
invoice = client.invoices.create(
  invoice_number: 'INV-2026-001',
  buyer_name: 'Acme Corporation',
  buyer_vat_number: '300000000000003',
  lines: [
    {
      description: 'Consulting Services',
      quantity: 10,
      unit_price: 100.00,
      tax_rate: 15.0
    }
  ]
)

puts "Invoice created: #{invoice.id}"

# Submit to ZATCA
result = client.compliance.submit(invoice.id)

if result.cleared?
  puts "Invoice cleared by ZATCA!"
  puts "QR Code: #{result.qr_code}"
end
```

## Features

### Invoice Management

```ruby
# List invoices
invoices = client.invoices.list(page: 1, limit: 20, status: 'cleared')

# Get single invoice
invoice = client.invoices.get('invoice-uuid')

# Create standard invoice (B2B)
b2b_invoice = client.invoices.create(
  invoice_number: 'INV-001',
  type: :standard,
  buyer_name: 'Business Customer',
  buyer_vat_number: '300000000000003',
  lines: [{ description: 'Service', quantity: 1, unit_price: 1000.00 }]
)

# Create simplified invoice (B2C)
b2c_invoice = client.invoices.create(
  invoice_number: 'SINV-001',
  type: :simplified,
  buyer_name: 'Walk-in Customer',
  lines: [{ description: 'Product', quantity: 2, unit_price: 50.00 }]
)

# Create credit note
credit_note = client.invoices.create_credit_note(
  credit_note_number: 'CN-001',
  original_invoice_id: 'original-invoice-uuid',
  lines: [{ description: 'Returned Item', quantity: 1, unit_price: 100.00 }]
)
```

### ZATCA Compliance

```ruby
# Generate compliance data
generated = client.compliance.generate(invoice_id)
puts "Hash: #{generated.hash}"
puts "QR Code: #{generated.qr_code}"

# Validate before submission
validation = client.compliance.validate(invoice_id)
validation.warnings.each { |w| puts "Warning: #{w}" }

# Submit to ZATCA
result = client.compliance.submit(invoice_id)

# Check status
status = client.compliance.status(invoice_id)
```

### Webhooks

```ruby
# Subscribe to events
webhook = client.webhooks.create(
  url: 'https://your-app.com/webhooks/masaar',
  events: ['invoice.cleared', 'invoice.rejected'],
  secret: 'your-webhook-secret'
)

# In your Rails controller
class WebhooksController < ApplicationController
  skip_before_action :verify_authenticity_token

  def masaar
    payload = request.raw_post
    signature = request.headers['X-Masaar-Signature']

    unless Masaar::Webhook.verify_signature(payload, signature, ENV['WEBHOOK_SECRET'])
      head :unauthorized
      return
    end

    event = JSON.parse(payload)

    case event['type']
    when 'invoice.cleared'
      handle_invoice_cleared(event)
    when 'invoice.rejected'
      handle_invoice_rejected(event)
    end

    head :ok
  end
end
```

## Error Handling

```ruby
begin
  invoice = client.invoices.create(params)
rescue Masaar::AuthenticationError => e
  puts "Auth failed: #{e.message}"
rescue Masaar::ValidationError => e
  puts "Validation failed: #{e.message}"
  e.errors.each { |err| puts "  - #{err}" }
rescue Masaar::ZatcaError => e
  puts "ZATCA error: #{e.message}"
rescue Masaar::RateLimitError => e
  puts "Rate limited - retry after #{e.retry_after} seconds"
rescue Masaar::Error => e
  puts "API error: #{e.message}"
end
```

## Rails Integration

```ruby
# config/initializers/masaar.rb
Masaar.configure do |config|
  config.base_url = ENV['MASAAR_BASE_URL'] || 'http://localhost:8000'
  config.api_key = ENV['MASAAR_API_KEY']
  config.api_secret = ENV['MASAAR_API_SECRET']
  config.timeout = 30
end

# Usage in your app
client = Masaar::Client.new
invoice = client.invoices.create(...)
```

## Requirements

- Ruby 2.7 or higher
- No external dependencies (uses Net::HTTP)

## License

MIT License - see LICENSE file for details.

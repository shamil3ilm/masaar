# frozen_string_literal: true

require 'net/http'
require 'json'
require 'uri'
require 'openssl'

# CompliPay Ruby SDK for ZATCA-compliant e-invoicing
#
# @example
#   client = CompliPay::Client.new(
#     base_url: 'https://api.complipay.com',
#     api_key: 'your_api_key',
#     api_secret: 'your_api_secret'
#   )
#
#   invoice = client.invoices.create(
#     invoice_number: 'INV-001',
#     buyer_name: 'Acme Corp',
#     lines: [{ description: 'Service', quantity: 1, unit_price: 100 }]
#   )
#
#   result = client.compliance.submit(invoice['id'])
module CompliPay
  VERSION = '1.0.0'

  class Error < StandardError; end
  class AuthenticationError < Error; end
  class ValidationError < Error; end
  class ZatcaError < Error; end
  class NetworkError < Error; end
  class RateLimitError < Error; end

  # Main API client
  class Client
    attr_reader :base_url, :api_key, :api_secret, :timeout

    def initialize(base_url:, api_key:, api_secret:, timeout: 30)
      @base_url = base_url.chomp('/')
      @api_key = api_key
      @api_secret = api_secret
      @timeout = timeout
    end

    def invoices
      @invoices ||= InvoicesResource.new(self)
    end

    def compliance
      @compliance ||= ComplianceResource.new(self)
    end

    def webhooks
      @webhooks ||= WebhooksResource.new(self)
    end

    def get(endpoint, params = {})
      request(:get, endpoint, params)
    end

    def post(endpoint, body = nil)
      request(:post, endpoint, body)
    end

    def delete(endpoint)
      request(:delete, endpoint)
    end

    private

    def request(method, endpoint, body = nil)
      uri = URI.parse("#{@base_url}#{endpoint}")

      http = Net::HTTP.new(uri.host, uri.port)
      http.use_ssl = uri.scheme == 'https'
      http.open_timeout = @timeout
      http.read_timeout = @timeout

      request = case method
                when :get
                  uri.query = URI.encode_www_form(body) if body.is_a?(Hash) && !body.empty?
                  Net::HTTP::Get.new(uri)
                when :post
                  req = Net::HTTP::Post.new(uri)
                  req.body = body.to_json if body
                  req
                when :delete
                  Net::HTTP::Delete.new(uri)
                end

      request['Content-Type'] = 'application/json'
      request['Accept'] = 'application/json'
      request['X-Api-Key'] = @api_key
      request['X-Api-Secret'] = @api_secret

      response = http.request(request)
      handle_response(response)
    rescue Net::OpenTimeout, Net::ReadTimeout, SocketError => e
      raise NetworkError, "Network error: #{e.message}"
    end

    def handle_response(response)
      body = JSON.parse(response.body) rescue { 'message' => response.body }

      case response.code.to_i
      when 200..299
        body
      when 401
        raise AuthenticationError, body['message'] || 'Invalid API credentials'
      when 422
        raise ValidationError, body['message'] || 'Validation failed'
      when 429
        raise RateLimitError, 'Rate limit exceeded'
      else
        raise Error, body['message'] || "Request failed with status #{response.code}"
      end
    end
  end

  # Invoices resource
  class InvoicesResource
    def initialize(client)
      @client = client
    end

    def list(page: 1, per_page: 15, status: nil)
      params = { page: page, per_page: per_page }
      params[:status] = status if status
      @client.get('/v1/invoices', params)
    end

    def get(invoice_id)
      @client.get("/v1/invoices/#{invoice_id}")
    end

    def create(invoice_number:, buyer_name:, lines:, **options)
      body = {
        invoice_number: invoice_number,
        buyer_name: buyer_name,
        lines: lines,
        type: options[:type] || 'standard',
        currency: options[:currency] || 'SAR',
        payment_means_code: options[:payment_means_code] || '10'
      }
      body[:buyer_vat_number] = options[:buyer_vat_number] if options[:buyer_vat_number]
      body[:buyer_address] = options[:buyer_address] if options[:buyer_address]
      body[:issue_date] = options[:issue_date] if options[:issue_date]
      body[:billing_reference_id] = options[:billing_reference_id] if options[:billing_reference_id]

      @client.post('/v1/invoices', body)
    end

    def create_credit_note(invoice_number:, buyer_name:, billing_reference_id:, lines:, **options)
      create(
        invoice_number: invoice_number,
        buyer_name: buyer_name,
        lines: lines,
        type: 'credit_note',
        billing_reference_id: billing_reference_id,
        **options
      )
    end
  end

  # Compliance resource
  class ComplianceResource
    def initialize(client)
      @client = client
    end

    def generate(invoice_id)
      @client.post("/api/compliance/zatca/generate/#{invoice_id}")
    end

    def validate(invoice_id)
      @client.post("/api/compliance/zatca/validate/#{invoice_id}")
    end

    def submit(invoice_id)
      result = @client.post("/api/compliance/zatca/submit/#{invoice_id}")
      raise ZatcaError, result['message'] unless result['success']
      result
    end

    def status(invoice_id)
      @client.get("/api/compliance/zatca/status/#{invoice_id}")
    end
  end

  # Webhooks resource
  class WebhooksResource
    EVENTS = %w[
      invoice.created
      invoice.submitted
      invoice.cleared
      invoice.reported
      invoice.rejected
      invoice.warning
      invoice.failed
    ].freeze

    def initialize(client)
      @client = client
    end

    def list
      @client.get('/api/webhooks')
    end

    def create(url:, events:, secret: nil)
      body = { url: url, events: events }
      body[:secret] = secret if secret
      @client.post('/api/webhooks', body)
    end

    def delete(webhook_id)
      @client.delete("/api/webhooks/#{webhook_id}")
    end

    # Verify webhook signature
    def self.verify_signature(payload, signature, secret)
      expected = 'sha256=' + OpenSSL::HMAC.hexdigest('SHA256', secret, payload)
      Rack::Utils.secure_compare(expected, signature)
    rescue StandardError
      expected == signature
    end
  end
end

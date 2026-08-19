// Package masaar provides a Go client for the Masaar ZATCA e-invoicing API.
//
// Example usage:
//
//	client := masaar.NewClient("https://api.masaar.sa", "api_key", "api_secret")
//
//	invoice, err := client.Invoices.Create(context.Background(), &masaar.CreateInvoiceRequest{
//	    InvoiceNumber: "INV-001",
//	    BuyerName:     "Acme Corp",
//	    Lines: []masaar.InvoiceLine{
//	        {Description: "Service", Quantity: 1, UnitPrice: 100},
//	    },
//	})
//
//	result, err := client.Compliance.Submit(context.Background(), invoice.ID)
package masaar

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"time"
)

// Client is the Masaar API client.
type Client struct {
	BaseURL    string
	APIKey     string
	APISecret  string
	HTTPClient *http.Client

	Invoices   *InvoicesService
	Compliance *ComplianceService
	Webhooks   *WebhooksService
}

// NewClient creates a new Masaar API client.
func NewClient(baseURL, apiKey, apiSecret string) *Client {
	c := &Client{
		BaseURL:   baseURL,
		APIKey:    apiKey,
		APISecret: apiSecret,
		HTTPClient: &http.Client{
			Timeout: 30 * time.Second,
		},
	}
	c.Invoices = &InvoicesService{client: c}
	c.Compliance = &ComplianceService{client: c}
	c.Webhooks = &WebhooksService{client: c}
	return c
}

// APIResponse wraps API responses.
type APIResponse[T any] struct {
	Success bool     `json:"success"`
	Data    T        `json:"data,omitempty"`
	Message string   `json:"message,omitempty"`
	Errors  []string `json:"errors,omitempty"`
}

// Invoice represents a ZATCA-compliant invoice.
type Invoice struct {
	ID              string  `json:"id"`
	InvoiceNumber   string  `json:"invoice_number"`
	Type            string  `json:"type"`
	Status          string  `json:"status"`
	BuyerName       string  `json:"buyer_name"`
	BuyerVATNumber  string  `json:"buyer_vat_number,omitempty"`
	Subtotal        float64 `json:"subtotal"`
	TaxAmount       float64 `json:"tax_amount"`
	Total           float64 `json:"total"`
	Currency        string  `json:"currency"`
	Hash            string  `json:"hash,omitempty"`
	QRCode          string  `json:"qr_code,omitempty"`
	ClearanceStatus string  `json:"clearance_status,omitempty"`
	ReportingStatus string  `json:"reporting_status,omitempty"`
	CreatedAt       string  `json:"created_at"`
}

// InvoiceLine represents an invoice line item.
type InvoiceLine struct {
	Description            string  `json:"description"`
	Quantity               float64 `json:"quantity"`
	UnitPrice              float64 `json:"unit_price"`
	TaxRate                float64 `json:"tax_rate,omitempty"`
	TaxCategory            string  `json:"tax_category,omitempty"`
	UnitCode               string  `json:"unit_code,omitempty"`
	TaxExemptionCode       string  `json:"tax_exemption_code,omitempty"`
	TaxExemptionReason     string  `json:"tax_exemption_reason,omitempty"`
	ItemClassificationCode string  `json:"item_classification_code,omitempty"`
	Discount               float64 `json:"discount,omitempty"`
}

// CreateInvoiceRequest is the request for creating an invoice.
type CreateInvoiceRequest struct {
	InvoiceNumber      string        `json:"invoice_number"`
	Type               string        `json:"type,omitempty"`
	BuyerName          string        `json:"buyer_name"`
	BuyerVATNumber     string        `json:"buyer_vat_number,omitempty"`
	BuyerAddress       *Address      `json:"buyer_address,omitempty"`
	IssueDate          string        `json:"issue_date,omitempty"`
	Currency           string        `json:"currency,omitempty"`
	PaymentMeansCode   string        `json:"payment_means_code,omitempty"`
	DiscountAmount     float64       `json:"discount_amount,omitempty"`
	Notes              string        `json:"notes,omitempty"`
	BillingReferenceID string        `json:"billing_reference_id,omitempty"`
	Lines              []InvoiceLine `json:"lines"`
}

// Address represents a postal address.
type Address struct {
	Street      string `json:"street,omitempty"`
	City        string `json:"city,omitempty"`
	PostalCode  string `json:"postal_code,omitempty"`
	District    string `json:"district,omitempty"`
	CountryCode string `json:"country_code,omitempty"`
}

// ZATCAResult represents ZATCA submission result.
type ZATCAResult struct {
	InvoiceID        string   `json:"invoice_id"`
	Status           string   `json:"status"`
	Hash             string   `json:"hash,omitempty"`
	QRCode           string   `json:"qr_code,omitempty"`
	ClearanceStatus  string   `json:"clearance_status,omitempty"`
	ReportingStatus  string   `json:"reporting_status,omitempty"`
	ValidationStatus string   `json:"validation_status,omitempty"`
	Warnings         []string `json:"warnings,omitempty"`
	Errors           []string `json:"errors,omitempty"`
}

// Error types
type APIError struct {
	StatusCode int
	Message    string
	Errors     []string
}

func (e *APIError) Error() string {
	return fmt.Sprintf("API error %d: %s", e.StatusCode, e.Message)
}

// doRequest performs an HTTP request.
func (c *Client) doRequest(ctx context.Context, method, endpoint string, body any, result any) error {
	var bodyReader io.Reader
	if body != nil {
		jsonBody, err := json.Marshal(body)
		if err != nil {
			return fmt.Errorf("failed to marshal request: %w", err)
		}
		bodyReader = bytes.NewReader(jsonBody)
	}

	req, err := http.NewRequestWithContext(ctx, method, c.BaseURL+endpoint, bodyReader)
	if err != nil {
		return fmt.Errorf("failed to create request: %w", err)
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("X-Api-Key", c.APIKey)
	req.Header.Set("X-Api-Secret", c.APISecret)

	resp, err := c.HTTPClient.Do(req)
	if err != nil {
		return fmt.Errorf("request failed: %w", err)
	}
	defer resp.Body.Close()

	respBody, err := io.ReadAll(resp.Body)
	if err != nil {
		return fmt.Errorf("failed to read response: %w", err)
	}

	if resp.StatusCode >= 400 {
		var apiResp APIResponse[any]
		json.Unmarshal(respBody, &apiResp)
		return &APIError{
			StatusCode: resp.StatusCode,
			Message:    apiResp.Message,
			Errors:     apiResp.Errors,
		}
	}

	if result != nil {
		if err := json.Unmarshal(respBody, result); err != nil {
			return fmt.Errorf("failed to unmarshal response: %w", err)
		}
	}

	return nil
}

// InvoicesService handles invoice operations.
type InvoicesService struct {
	client *Client
}

// List retrieves invoices with pagination.
func (s *InvoicesService) List(ctx context.Context, page, perPage int) (*APIResponse[[]Invoice], error) {
	var result APIResponse[[]Invoice]
	endpoint := fmt.Sprintf("/v1/invoices?page=%d&per_page=%d", page, perPage)
	err := s.client.doRequest(ctx, http.MethodGet, endpoint, nil, &result)
	return &result, err
}

// Get retrieves a single invoice.
func (s *InvoicesService) Get(ctx context.Context, invoiceID string) (*APIResponse[Invoice], error) {
	var result APIResponse[Invoice]
	err := s.client.doRequest(ctx, http.MethodGet, "/v1/invoices/"+invoiceID, nil, &result)
	return &result, err
}

// Create creates a new invoice.
func (s *InvoicesService) Create(ctx context.Context, req *CreateInvoiceRequest) (*APIResponse[Invoice], error) {
	var result APIResponse[Invoice]
	err := s.client.doRequest(ctx, http.MethodPost, "/v1/invoices", req, &result)
	return &result, err
}

// ComplianceService handles ZATCA compliance operations.
type ComplianceService struct {
	client *Client
}

// Generate generates compliance data for an invoice.
func (s *ComplianceService) Generate(ctx context.Context, invoiceID string) (*APIResponse[ZATCAResult], error) {
	var result APIResponse[ZATCAResult]
	err := s.client.doRequest(ctx, http.MethodPost, "/api/compliance/zatca/generate/"+invoiceID, nil, &result)
	return &result, err
}

// Validate validates an invoice without submitting.
func (s *ComplianceService) Validate(ctx context.Context, invoiceID string) (*APIResponse[ZATCAResult], error) {
	var result APIResponse[ZATCAResult]
	err := s.client.doRequest(ctx, http.MethodPost, "/api/compliance/zatca/validate/"+invoiceID, nil, &result)
	return &result, err
}

// Submit submits an invoice to ZATCA.
func (s *ComplianceService) Submit(ctx context.Context, invoiceID string) (*APIResponse[ZATCAResult], error) {
	var result APIResponse[ZATCAResult]
	err := s.client.doRequest(ctx, http.MethodPost, "/api/compliance/zatca/submit/"+invoiceID, nil, &result)
	return &result, err
}

// Status gets ZATCA compliance status.
func (s *ComplianceService) Status(ctx context.Context, invoiceID string) (*APIResponse[ZATCAResult], error) {
	var result APIResponse[ZATCAResult]
	err := s.client.doRequest(ctx, http.MethodGet, "/api/compliance/zatca/status/"+invoiceID, nil, &result)
	return &result, err
}

// WebhooksService handles webhook operations.
type WebhooksService struct {
	client *Client
}

// Webhook events
const (
	EventInvoiceCreated   = "invoice.created"
	EventInvoiceSubmitted = "invoice.submitted"
	EventInvoiceCleared   = "invoice.cleared"
	EventInvoiceReported  = "invoice.reported"
	EventInvoiceRejected  = "invoice.rejected"
	EventInvoiceWarning   = "invoice.warning"
	EventInvoiceFailed    = "invoice.failed"
)

// VerifySignature verifies a webhook signature.
func VerifySignature(payload []byte, signature, secret string) bool {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write(payload)
	expected := "sha256=" + hex.EncodeToString(mac.Sum(nil))
	return hmac.Equal([]byte(expected), []byte(signature))
}

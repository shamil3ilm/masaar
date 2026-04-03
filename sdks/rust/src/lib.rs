//! CompliPay Rust SDK for ZATCA-compliant e-invoicing.
//!
//! # Example
//! ```rust,no_run
//! use complipay::{CompliPayClient, CreateInvoiceRequest, InvoiceLine};
//!
//! #[tokio::main]
//! async fn main() -> Result<(), complipay::Error> {
//!     let client = CompliPayClient::new(
//!         "https://api.complipay.com",
//!         "your_api_key",
//!         "your_api_secret",
//!     );
//!
//!     let invoice = client.invoices().create(&CreateInvoiceRequest {
//!         invoice_number: "INV-001".to_string(),
//!         buyer_name: "Acme Corp".to_string(),
//!         lines: vec![InvoiceLine {
//!             description: "Service".to_string(),
//!             quantity: 1.0,
//!             unit_price: 100.0,
//!             ..Default::default()
//!         }],
//!         ..Default::default()
//!     }).await?;
//!
//!     let result = client.compliance().submit(&invoice.data.unwrap().id).await?;
//!     Ok(())
//! }
//! ```

use hmac::{Hmac, Mac};
use reqwest::{Client, Response};
use serde::{Deserialize, Serialize};
use sha2::Sha256;
use std::time::Duration;
use thiserror::Error;

type HmacSha256 = Hmac<Sha256>;

// MARK: - Errors

#[derive(Error, Debug)]
pub enum Error {
    #[error("HTTP error: {0}")]
    Http(#[from] reqwest::Error),

    #[error("Authentication failed: {0}")]
    Authentication(String),

    #[error("Validation failed: {0}")]
    Validation(String, Option<Vec<String>>),

    #[error("Rate limit exceeded")]
    RateLimit,

    #[error("API error ({0}): {1}")]
    Api(u16, String),

    #[error("ZATCA error: {0}")]
    Zatca(String, Option<Vec<String>>),

    #[error("Serialization error: {0}")]
    Serialization(#[from] serde_json::Error),
}

// MARK: - Models

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ApiResponse<T> {
    pub success: bool,
    pub data: Option<T>,
    pub message: Option<String>,
    pub errors: Option<Vec<String>>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Invoice {
    pub id: String,
    pub invoice_number: String,
    #[serde(rename = "type")]
    pub invoice_type: String,
    pub status: String,
    pub buyer_name: String,
    pub buyer_vat_number: Option<String>,
    pub subtotal: f64,
    pub tax_amount: f64,
    pub total: f64,
    pub currency: String,
    pub hash: Option<String>,
    pub qr_code: Option<String>,
    pub clearance_status: Option<String>,
    pub reporting_status: Option<String>,
    pub created_at: String,
}

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct InvoiceLine {
    pub description: String,
    pub quantity: f64,
    pub unit_price: f64,
    #[serde(default = "default_tax_rate")]
    pub tax_rate: f64,
    #[serde(default = "default_tax_category")]
    pub tax_category: String,
    #[serde(default = "default_unit_code")]
    pub unit_code: String,
    pub tax_exemption_code: Option<String>,
    pub tax_exemption_reason: Option<String>,
    #[serde(default)]
    pub discount: f64,
}

fn default_tax_rate() -> f64 { 15.0 }
fn default_tax_category() -> String { "S".to_string() }
fn default_unit_code() -> String { "PCE".to_string() }

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct CreateInvoiceRequest {
    pub invoice_number: String,
    #[serde(rename = "type", default = "default_invoice_type")]
    pub invoice_type: String,
    pub buyer_name: String,
    pub buyer_vat_number: Option<String>,
    pub buyer_address: Option<Address>,
    pub issue_date: Option<String>,
    #[serde(default = "default_currency")]
    pub currency: String,
    #[serde(default = "default_payment_means")]
    pub payment_means_code: String,
    #[serde(default)]
    pub discount_amount: f64,
    pub notes: Option<String>,
    pub billing_reference_id: Option<String>,
    pub lines: Vec<InvoiceLine>,
}

fn default_invoice_type() -> String { "standard".to_string() }
fn default_currency() -> String { "SAR".to_string() }
fn default_payment_means() -> String { "10".to_string() }

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct Address {
    pub street: String,
    pub city: String,
    pub postal_code: String,
    pub district: Option<String>,
    #[serde(default = "default_country")]
    pub country_code: String,
}

fn default_country() -> String { "SA".to_string() }

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct ZatcaResult {
    pub invoice_id: Option<String>,
    pub status: Option<String>,
    pub hash: Option<String>,
    pub qr_code: Option<String>,
    pub clearance_status: Option<String>,
    pub reporting_status: Option<String>,
    pub validation_status: Option<String>,
    pub warnings: Option<Vec<String>>,
    pub errors: Option<Vec<String>>,
}

impl ZatcaResult {
    pub fn is_cleared(&self) -> bool {
        self.clearance_status.as_deref() == Some("CLEARED")
    }

    pub fn is_reported(&self) -> bool {
        self.reporting_status.as_deref() == Some("REPORTED")
    }
}

// MARK: - Client

pub struct CompliPayClient {
    base_url: String,
    api_key: String,
    api_secret: String,
    client: Client,
}

impl CompliPayClient {
    pub fn new(base_url: &str, api_key: &str, api_secret: &str) -> Self {
        let client = Client::builder()
            .timeout(Duration::from_secs(30))
            .build()
            .expect("Failed to create HTTP client");

        Self {
            base_url: base_url.trim_end_matches('/').to_string(),
            api_key: api_key.to_string(),
            api_secret: api_secret.to_string(),
            client,
        }
    }

    pub fn invoices(&self) -> InvoicesResource {
        InvoicesResource { client: self }
    }

    pub fn compliance(&self) -> ComplianceResource {
        ComplianceResource { client: self }
    }

    pub fn webhooks(&self) -> WebhooksResource {
        WebhooksResource { client: self }
    }

    async fn get<T: for<'de> Deserialize<'de>>(&self, endpoint: &str) -> Result<ApiResponse<T>, Error> {
        self.request("GET", endpoint, None::<()>).await
    }

    async fn post<T, B>(&self, endpoint: &str, body: Option<&B>) -> Result<ApiResponse<T>, Error>
    where
        T: for<'de> Deserialize<'de>,
        B: Serialize,
    {
        self.request("POST", endpoint, body).await
    }

    async fn request<T, B>(&self, method: &str, endpoint: &str, body: Option<&B>) -> Result<ApiResponse<T>, Error>
    where
        T: for<'de> Deserialize<'de>,
        B: Serialize,
    {
        let url = format!("{}{}", self.base_url, endpoint);

        let mut request = match method {
            "GET" => self.client.get(&url),
            "POST" => self.client.post(&url),
            "DELETE" => self.client.delete(&url),
            _ => panic!("Unsupported method"),
        };

        request = request
            .header("Content-Type", "application/json")
            .header("Accept", "application/json")
            .header("X-Api-Key", &self.api_key)
            .header("X-Api-Secret", &self.api_secret);

        if let Some(body) = body {
            request = request.json(body);
        }

        let response = request.send().await?;
        self.handle_response(response).await
    }

    async fn handle_response<T: for<'de> Deserialize<'de>>(&self, response: Response) -> Result<ApiResponse<T>, Error> {
        let status = response.status().as_u16();
        let text = response.text().await?;

        if status >= 400 {
            let error: ApiResponse<()> = serde_json::from_str(&text).unwrap_or(ApiResponse {
                success: false,
                data: None,
                message: Some(text.clone()),
                errors: None,
            });

            let message = error.message.unwrap_or_else(|| "Request failed".to_string());

            return Err(match status {
                401 => Error::Authentication(message),
                422 => Error::Validation(message, error.errors),
                429 => Error::RateLimit,
                _ => Error::Api(status, message),
            });
        }

        Ok(serde_json::from_str(&text)?)
    }
}

// MARK: - Resources

pub struct InvoicesResource<'a> {
    client: &'a CompliPayClient,
}

impl<'a> InvoicesResource<'a> {
    pub async fn get(&self, invoice_id: &str) -> Result<ApiResponse<Invoice>, Error> {
        self.client.get(&format!("/v1/invoices/{}", invoice_id)).await
    }

    pub async fn create(&self, request: &CreateInvoiceRequest) -> Result<ApiResponse<Invoice>, Error> {
        self.client.post("/v1/invoices", Some(request)).await
    }
}

pub struct ComplianceResource<'a> {
    client: &'a CompliPayClient,
}

impl<'a> ComplianceResource<'a> {
    pub async fn generate(&self, invoice_id: &str) -> Result<ApiResponse<ZatcaResult>, Error> {
        self.client.post::<ZatcaResult, ()>(&format!("/api/compliance/zatca/generate/{}", invoice_id), None).await
    }

    pub async fn validate(&self, invoice_id: &str) -> Result<ApiResponse<ZatcaResult>, Error> {
        self.client.post::<ZatcaResult, ()>(&format!("/api/compliance/zatca/validate/{}", invoice_id), None).await
    }

    pub async fn submit(&self, invoice_id: &str) -> Result<ApiResponse<ZatcaResult>, Error> {
        self.client.post::<ZatcaResult, ()>(&format!("/api/compliance/zatca/submit/{}", invoice_id), None).await
    }

    pub async fn status(&self, invoice_id: &str) -> Result<ApiResponse<ZatcaResult>, Error> {
        self.client.get(&format!("/api/compliance/zatca/status/{}", invoice_id)).await
    }
}

pub struct WebhooksResource<'a> {
    #[allow(dead_code)]
    client: &'a CompliPayClient,
}

impl<'a> WebhooksResource<'a> {
    pub const INVOICE_CREATED: &'static str = "invoice.created";
    pub const INVOICE_SUBMITTED: &'static str = "invoice.submitted";
    pub const INVOICE_CLEARED: &'static str = "invoice.cleared";
    pub const INVOICE_REPORTED: &'static str = "invoice.reported";
    pub const INVOICE_REJECTED: &'static str = "invoice.rejected";
    pub const INVOICE_WARNING: &'static str = "invoice.warning";
    pub const INVOICE_FAILED: &'static str = "invoice.failed";

    pub fn verify_signature(payload: &[u8], signature: &str, secret: &str) -> bool {
        let mut mac = HmacSha256::new_from_slice(secret.as_bytes())
            .expect("HMAC can take key of any size");
        mac.update(payload);
        let result = mac.finalize();
        let expected = format!("sha256={}", hex::encode(result.into_bytes()));
        expected == signature
    }
}

"""
CompliPay Python SDK

ZATCA-compliant e-invoicing API client for Python 3.7+
Works with Django, Flask, FastAPI, or any Python application.

Usage:
    from complipay import CompliPayClient

    client = CompliPayClient(
        base_url="https://api.masaar.sa",
        api_key="your_api_key"
    )

    # Create invoice
    invoice = client.invoices.create({
        "invoice_number": "INV-001",
        "buyer_name": "Acme Corp",
        "lines": [...]
    })

    # Submit to ZATCA
    result = client.compliance.submit(invoice["id"])
"""

import json
import hmac
import hashlib
from typing import Any, Dict, List, Optional, Union
from dataclasses import dataclass
from datetime import datetime

try:
    import requests
except ImportError:
    requests = None

try:
    import httpx
except ImportError:
    httpx = None


class CompliPayError(Exception):
    """Base exception for CompliPay SDK."""
    def __init__(self, message: str, status_code: int = None, errors: List[str] = None):
        self.message = message
        self.status_code = status_code
        self.errors = errors or []
        super().__init__(self.message)


class AuthenticationError(CompliPayError):
    """Raised when authentication fails."""
    pass


class ValidationError(CompliPayError):
    """Raised when request validation fails."""
    pass


class ZatcaError(CompliPayError):
    """Raised when ZATCA submission fails."""
    pass


@dataclass
class InvoiceLine:
    """Invoice line item."""
    description: str
    quantity: float
    unit_price: float
    tax_rate: float = 15.0
    tax_category: str = "S"
    unit_code: str = "PCE"
    tax_exemption_code: Optional[str] = None
    tax_exemption_reason: Optional[str] = None
    item_classification_code: Optional[str] = None
    discount: float = 0.0

    def to_dict(self) -> Dict[str, Any]:
        data = {
            "description": self.description,
            "quantity": self.quantity,
            "unit_price": self.unit_price,
            "tax_rate": self.tax_rate,
            "tax_category": self.tax_category,
            "unit_code": self.unit_code,
        }
        if self.tax_exemption_code:
            data["tax_exemption_code"] = self.tax_exemption_code
        if self.tax_exemption_reason:
            data["tax_exemption_reason"] = self.tax_exemption_reason
        if self.item_classification_code:
            data["item_classification_code"] = self.item_classification_code
        if self.discount > 0:
            data["discount"] = self.discount
        return data


class HttpClient:
    """HTTP client abstraction supporting requests and httpx."""

    def __init__(self, base_url: str, api_key: str = None, jwt_token: str = None, timeout: int = 30):
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key
        self.jwt_token = jwt_token
        self.timeout = timeout

        if requests:
            self._client = "requests"
        elif httpx:
            self._client = "httpx"
        else:
            raise ImportError("Please install 'requests' or 'httpx': pip install requests")

    def _headers(self) -> Dict[str, str]:
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
        }
        if self.api_key:
            headers["X-API-Key"] = self.api_key
        elif self.jwt_token:
            headers["Authorization"] = f"Bearer {self.jwt_token}"
        return headers

    def _handle_response(self, response) -> Dict[str, Any]:
        if self._client == "requests":
            status_code = response.status_code
            try:
                data = response.json()
            except:
                data = {"message": response.text}
        else:
            status_code = response.status_code
            try:
                data = response.json()
            except:
                data = {"message": response.text}

        if status_code == 401:
            raise AuthenticationError("Invalid API key or token", status_code)
        elif status_code == 422:
            raise ValidationError(
                data.get("message", "Validation failed"),
                status_code,
                data.get("errors", [])
            )
        elif status_code >= 400:
            raise CompliPayError(
                data.get("message", f"Request failed with status {status_code}"),
                status_code,
                data.get("errors", [])
            )

        return data

    def get(self, endpoint: str, params: Dict = None) -> Dict[str, Any]:
        url = f"{self.base_url}{endpoint}"
        if self._client == "requests":
            response = requests.get(url, headers=self._headers(), params=params, timeout=self.timeout)
        else:
            response = httpx.get(url, headers=self._headers(), params=params, timeout=self.timeout)
        return self._handle_response(response)

    def post(self, endpoint: str, data: Dict = None) -> Dict[str, Any]:
        url = f"{self.base_url}{endpoint}"
        if self._client == "requests":
            response = requests.post(url, headers=self._headers(), json=data, timeout=self.timeout)
        else:
            response = httpx.post(url, headers=self._headers(), json=data, timeout=self.timeout)
        return self._handle_response(response)

    def put(self, endpoint: str, data: Dict = None) -> Dict[str, Any]:
        url = f"{self.base_url}{endpoint}"
        if self._client == "requests":
            response = requests.put(url, headers=self._headers(), json=data, timeout=self.timeout)
        else:
            response = httpx.put(url, headers=self._headers(), json=data, timeout=self.timeout)
        return self._handle_response(response)

    def delete(self, endpoint: str) -> Dict[str, Any]:
        url = f"{self.base_url}{endpoint}"
        if self._client == "requests":
            response = requests.delete(url, headers=self._headers(), timeout=self.timeout)
        else:
            response = httpx.delete(url, headers=self._headers(), timeout=self.timeout)
        return self._handle_response(response)


class InvoicesResource:
    """Invoice management resource."""

    def __init__(self, client: HttpClient):
        self._client = client

    def list(self, page: int = 1, per_page: int = 15, status: str = None) -> Dict[str, Any]:
        """List invoices with pagination."""
        params = {"page": page, "per_page": per_page}
        if status:
            params["status"] = status
        return self._client.get("/v1/invoices", params)

    def get(self, invoice_id: str) -> Dict[str, Any]:
        """Get invoice by ID."""
        return self._client.get(f"/v1/invoices/{invoice_id}")

    def create(
        self,
        invoice_number: str,
        buyer_name: str,
        lines: List[Union[Dict, InvoiceLine]],
        invoice_type: str = "standard",
        buyer_vat_number: str = None,
        buyer_address: Dict = None,
        issue_date: str = None,
        currency: str = "SAR",
        payment_means_code: str = "10",
        discount_amount: float = 0.0,
        notes: str = None,
        billing_reference_id: str = None,
    ) -> Dict[str, Any]:
        """Create a new invoice."""
        data = {
            "invoice_number": invoice_number,
            "type": invoice_type,
            "buyer_name": buyer_name,
            "currency": currency,
            "payment_means_code": payment_means_code,
            "lines": [
                line.to_dict() if isinstance(line, InvoiceLine) else line
                for line in lines
            ],
        }

        if buyer_vat_number:
            data["buyer_vat_number"] = buyer_vat_number
        if buyer_address:
            data["buyer_address"] = buyer_address
        if issue_date:
            data["issue_date"] = issue_date
        if discount_amount > 0:
            data["discount_amount"] = discount_amount
        if notes:
            data["notes"] = notes
        if billing_reference_id:
            data["billing_reference_id"] = billing_reference_id

        return self._client.post("/v1/invoices", data)

    def create_credit_note(
        self,
        invoice_number: str,
        buyer_name: str,
        lines: List[Union[Dict, InvoiceLine]],
        billing_reference_id: str,
        adjustment_reason: str,
        **kwargs
    ) -> Dict[str, Any]:
        """Create a credit note (must reference original invoice)."""
        return self.create(
            invoice_number=invoice_number,
            buyer_name=buyer_name,
            lines=lines,
            invoice_type="credit_note",
            billing_reference_id=billing_reference_id,
            **kwargs
        )

    def create_debit_note(
        self,
        invoice_number: str,
        buyer_name: str,
        lines: List[Union[Dict, InvoiceLine]],
        billing_reference_id: str,
        adjustment_reason: str,
        **kwargs
    ) -> Dict[str, Any]:
        """Create a debit note (must reference original invoice)."""
        return self.create(
            invoice_number=invoice_number,
            buyer_name=buyer_name,
            lines=lines,
            invoice_type="debit_note",
            billing_reference_id=billing_reference_id,
            **kwargs
        )


class ComplianceResource:
    """ZATCA compliance resource."""

    def __init__(self, client: HttpClient):
        self._client = client

    def generate(self, invoice_id: str) -> Dict[str, Any]:
        """Generate compliance data (hash, QR code) for invoice."""
        return self._client.post(f"/api/compliance/zatca/generate/{invoice_id}")

    def validate(self, invoice_id: str) -> Dict[str, Any]:
        """Validate invoice with ZATCA without submission."""
        return self._client.post(f"/api/compliance/zatca/validate/{invoice_id}")

    def submit(self, invoice_id: str) -> Dict[str, Any]:
        """Submit invoice to ZATCA (clearance or reporting)."""
        result = self._client.post(f"/api/compliance/zatca/submit/{invoice_id}")
        if not result.get("success", True):
            raise ZatcaError(
                "ZATCA submission failed",
                errors=result.get("errors", [])
            )
        return result

    def status(self, invoice_id: str) -> Dict[str, Any]:
        """Get ZATCA compliance status for invoice."""
        return self._client.get(f"/api/compliance/zatca/status/{invoice_id}")


class WebhooksResource:
    """Webhook management resource."""

    def __init__(self, client: HttpClient):
        self._client = client

    def list(self) -> Dict[str, Any]:
        """List all webhooks."""
        return self._client.get("/api/webhooks")

    def create(
        self,
        url: str,
        events: List[str],
        secret: str = None,
    ) -> Dict[str, Any]:
        """Create a webhook subscription."""
        data = {"url": url, "events": events}
        if secret:
            data["secret"] = secret
        return self._client.post("/api/webhooks", data)

    def delete(self, webhook_id: str) -> Dict[str, Any]:
        """Delete a webhook."""
        return self._client.delete(f"/api/webhooks/{webhook_id}")

    @staticmethod
    def verify_signature(payload: bytes, signature: str, secret: str) -> bool:
        """Verify webhook signature."""
        expected = hmac.new(
            secret.encode(),
            payload,
            hashlib.sha256
        ).hexdigest()
        return hmac.compare_digest(f"sha256={expected}", signature)


class CompliPayClient:
    """
    CompliPay API Client.

    Supports Python 3.7+ with any HTTP library (requests, httpx).

    Usage:
        client = CompliPayClient(
            base_url="https://api.masaar.sa",
            api_key="your_api_key"
        )

        # Create and submit invoice
        invoice = client.invoices.create(
            invoice_number="INV-001",
            buyer_name="Acme Corp",
            buyer_vat_number="300000000000003",
            lines=[
                InvoiceLine(
                    description="Consulting",
                    quantity=10,
                    unit_price=100.00,
                    tax_rate=15.0
                )
            ]
        )

        # Generate compliance data
        client.compliance.generate(invoice["data"]["id"])

        # Submit to ZATCA
        result = client.compliance.submit(invoice["data"]["id"])
    """

    def __init__(
        self,
        base_url: str,
        api_key: str = None,
        jwt_token: str = None,
        timeout: int = 30,
    ):
        if not api_key and not jwt_token:
            raise ValueError("Either api_key or jwt_token must be provided")

        self._http = HttpClient(base_url, api_key, jwt_token, timeout)

        # Resources
        self.invoices = InvoicesResource(self._http)
        self.compliance = ComplianceResource(self._http)
        self.webhooks = WebhooksResource(self._http)

    def health(self) -> Dict[str, Any]:
        """Check API health."""
        return self._http.get("/api/health")


# Convenience exports
__all__ = [
    "CompliPayClient",
    "InvoiceLine",
    "CompliPayError",
    "AuthenticationError",
    "ValidationError",
    "ZatcaError",
]

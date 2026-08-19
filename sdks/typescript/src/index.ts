/**
 * Masaar TypeScript/JavaScript SDK
 *
 * ZATCA-compliant e-invoicing API client
 * Works with Node.js, React, Vue, Angular, or any JavaScript environment
 *
 * @example
 * ```typescript
 * import { MasaarClient } from 'masaar';
 *
 * const client = new MasaarClient({
 *   baseUrl: 'https://api.masaar.sa',
 *   apiKey: 'your_api_key'
 * });
 *
 * const invoice = await client.invoices.create({
 *   invoiceNumber: 'INV-001',
 *   buyerName: 'Acme Corp',
 *   lines: [{ description: 'Service', quantity: 1, unitPrice: 100 }]
 * });
 * ```
 */

// Types
export interface MasaarConfig {
  baseUrl: string;
  apiKey?: string;
  jwtToken?: string;
  timeout?: number;
}

export interface InvoiceLine {
  description: string;
  quantity: number;
  unitPrice: number;
  taxRate?: number;
  taxCategory?: 'S' | 'Z' | 'E' | 'O';
  unitCode?: string;
  taxExemptionCode?: string;
  taxExemptionReason?: string;
  itemClassificationCode?: string;
  discount?: number;
}

export interface CreateInvoiceParams {
  invoiceNumber: string;
  buyerName: string;
  lines: InvoiceLine[];
  type?: 'standard' | 'simplified' | 'credit_note' | 'debit_note';
  buyerVatNumber?: string;
  buyerAddress?: {
    street?: string;
    city?: string;
    postalCode?: string;
    district?: string;
    countryCode?: string;
  };
  issueDate?: string;
  currency?: string;
  paymentMeansCode?: string;
  discountAmount?: number;
  notes?: string;
  billingReferenceId?: string;
}

export interface ApiResponse<T = any> {
  success: boolean;
  data?: T;
  message?: string;
  errors?: string[];
}

export interface Invoice {
  id: string;
  invoiceNumber: string;
  type: string;
  status: string;
  buyerName: string;
  subtotal: number;
  taxAmount: number;
  total: number;
  hash?: string;
  qrCode?: string;
  createdAt: string;
}

export interface ZatcaStatus {
  invoiceId: string;
  status: string;
  hash?: string;
  qrCode?: string;
  zatcaResponse?: {
    clearanceStatus?: string;
    reportingStatus?: string;
    validationStatus?: string;
    warnings?: string[];
    errors?: string[];
  };
}

// Errors
export class MasaarError extends Error {
  statusCode?: number;
  errors: string[];

  constructor(message: string, statusCode?: number, errors: string[] = []) {
    super(message);
    this.name = 'MasaarError';
    this.statusCode = statusCode;
    this.errors = errors;
  }
}

export class AuthenticationError extends MasaarError {
  constructor(message = 'Invalid API key or token') {
    super(message, 401);
    this.name = 'AuthenticationError';
  }
}

export class ValidationError extends MasaarError {
  constructor(message: string, errors: string[] = []) {
    super(message, 422, errors);
    this.name = 'ValidationError';
  }
}

export class ZatcaError extends MasaarError {
  constructor(message: string, errors: string[] = []) {
    super(message, undefined, errors);
    this.name = 'ZatcaError';
  }
}

// HTTP Client
class HttpClient {
  private baseUrl: string;
  private apiKey?: string;
  private jwtToken?: string;
  private timeout: number;

  constructor(config: MasaarConfig) {
    this.baseUrl = config.baseUrl.replace(/\/$/, '');
    this.apiKey = config.apiKey;
    this.jwtToken = config.jwtToken;
    this.timeout = config.timeout || 30000;
  }

  private getHeaders(): Record<string, string> {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };

    if (this.apiKey) {
      headers['X-API-Key'] = this.apiKey;
    } else if (this.jwtToken) {
      headers['Authorization'] = `Bearer ${this.jwtToken}`;
    }

    return headers;
  }

  private async handleResponse<T>(response: Response): Promise<ApiResponse<T>> {
    let data: any;

    try {
      data = await response.json();
    } catch {
      data = { message: await response.text() };
    }

    if (response.status === 401) {
      throw new AuthenticationError();
    }

    if (response.status === 422) {
      throw new ValidationError(
        data.message || 'Validation failed',
        data.errors || []
      );
    }

    if (!response.ok) {
      throw new MasaarError(
        data.message || `Request failed with status ${response.status}`,
        response.status,
        data.errors || []
      );
    }

    return data;
  }

  async get<T>(endpoint: string, params?: Record<string, any>): Promise<ApiResponse<T>> {
    const url = new URL(`${this.baseUrl}${endpoint}`);
    if (params) {
      Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined) url.searchParams.append(key, String(value));
      });
    }

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), this.timeout);

    try {
      const response = await fetch(url.toString(), {
        method: 'GET',
        headers: this.getHeaders(),
        signal: controller.signal,
      });
      return this.handleResponse<T>(response);
    } finally {
      clearTimeout(timeoutId);
    }
  }

  async post<T>(endpoint: string, body?: any): Promise<ApiResponse<T>> {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), this.timeout);

    try {
      const response = await fetch(`${this.baseUrl}${endpoint}`, {
        method: 'POST',
        headers: this.getHeaders(),
        body: body ? JSON.stringify(body) : undefined,
        signal: controller.signal,
      });
      return this.handleResponse<T>(response);
    } finally {
      clearTimeout(timeoutId);
    }
  }

  async delete<T>(endpoint: string): Promise<ApiResponse<T>> {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), this.timeout);

    try {
      const response = await fetch(`${this.baseUrl}${endpoint}`, {
        method: 'DELETE',
        headers: this.getHeaders(),
        signal: controller.signal,
      });
      return this.handleResponse<T>(response);
    } finally {
      clearTimeout(timeoutId);
    }
  }
}

// Resources
class InvoicesResource {
  constructor(private http: HttpClient) {}

  async list(params?: { page?: number; perPage?: number; status?: string }) {
    return this.http.get<Invoice[]>('/v1/invoices', params);
  }

  async get(invoiceId: string) {
    return this.http.get<Invoice>(`/v1/invoices/${invoiceId}`);
  }

  async create(params: CreateInvoiceParams) {
    const body = {
      invoice_number: params.invoiceNumber,
      type: params.type || 'standard',
      buyer_name: params.buyerName,
      buyer_vat_number: params.buyerVatNumber,
      buyer_address: params.buyerAddress,
      issue_date: params.issueDate,
      currency: params.currency || 'SAR',
      payment_means_code: params.paymentMeansCode || '10',
      discount_amount: params.discountAmount,
      notes: params.notes,
      billing_reference_id: params.billingReferenceId,
      lines: params.lines.map(line => ({
        description: line.description,
        quantity: line.quantity,
        unit_price: line.unitPrice,
        tax_rate: line.taxRate ?? 15,
        tax_category: line.taxCategory || 'S',
        unit_code: line.unitCode || 'PCE',
        tax_exemption_code: line.taxExemptionCode,
        tax_exemption_reason: line.taxExemptionReason,
        item_classification_code: line.itemClassificationCode,
        discount: line.discount,
      })),
    };

    return this.http.post<Invoice>('/v1/invoices', body);
  }

  async createCreditNote(
    params: Omit<CreateInvoiceParams, 'type'> & {
      billingReferenceId: string;
      adjustmentReason: string;
    }
  ) {
    return this.create({ ...params, type: 'credit_note' });
  }

  async createDebitNote(
    params: Omit<CreateInvoiceParams, 'type'> & {
      billingReferenceId: string;
      adjustmentReason: string;
    }
  ) {
    return this.create({ ...params, type: 'debit_note' });
  }
}

class ComplianceResource {
  constructor(private http: HttpClient) {}

  async generate(invoiceId: string) {
    return this.http.post<{ hash: string; qrCode: string }>(
      `/api/compliance/zatca/generate/${invoiceId}`
    );
  }

  async validate(invoiceId: string) {
    return this.http.post<{
      valid: boolean;
      status: string;
      warnings: string[];
      errors: string[];
    }>(`/api/compliance/zatca/validate/${invoiceId}`);
  }

  async submit(invoiceId: string) {
    const result = await this.http.post<{
      status: string;
      warnings: string[];
    }>(`/api/compliance/zatca/submit/${invoiceId}`);

    if (!result.success) {
      throw new ZatcaError('ZATCA submission failed', result.errors || []);
    }

    return result;
  }

  async status(invoiceId: string) {
    return this.http.get<ZatcaStatus>(`/api/compliance/zatca/status/${invoiceId}`);
  }
}

class WebhooksResource {
  constructor(private http: HttpClient) {}

  async list() {
    return this.http.get<any[]>('/api/webhooks');
  }

  async create(params: { url: string; events: string[]; secret?: string }) {
    return this.http.post('/api/webhooks', params);
  }

  async delete(webhookId: string) {
    return this.http.delete(`/api/webhooks/${webhookId}`);
  }

  /**
   * Verify webhook signature (for Node.js)
   */
  static async verifySignature(
    payload: string | Buffer,
    signature: string,
    secret: string
  ): Promise<boolean> {
    // Node.js crypto
    if (typeof window === 'undefined') {
      const crypto = await import('crypto');
      const expected = crypto
        .createHmac('sha256', secret)
        .update(payload)
        .digest('hex');
      return `sha256=${expected}` === signature;
    }

    // Browser Web Crypto API
    const encoder = new TextEncoder();
    const key = await crypto.subtle.importKey(
      'raw',
      encoder.encode(secret),
      { name: 'HMAC', hash: 'SHA-256' },
      false,
      ['sign']
    );
    const sig = await crypto.subtle.sign(
      'HMAC',
      key,
      typeof payload === 'string' ? encoder.encode(payload) : payload
    );
    const expected = Array.from(new Uint8Array(sig))
      .map(b => b.toString(16).padStart(2, '0'))
      .join('');
    return `sha256=${expected}` === signature;
  }
}

// Main Client
export class MasaarClient {
  public invoices: InvoicesResource;
  public compliance: ComplianceResource;
  public webhooks: WebhooksResource;

  private http: HttpClient;

  constructor(config: MasaarConfig) {
    if (!config.apiKey && !config.jwtToken) {
      throw new Error('Either apiKey or jwtToken must be provided');
    }

    this.http = new HttpClient(config);
    this.invoices = new InvoicesResource(this.http);
    this.compliance = new ComplianceResource(this.http);
    this.webhooks = new WebhooksResource(this.http);
  }

  async health() {
    return this.http.get('/api/health');
  }
}

// Default export
export default MasaarClient;

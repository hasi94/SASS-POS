import { Injectable, computed, inject, signal } from '@angular/core';
import {
  ApiError,
  LINE_PRICER,
  PaymentMethod,
  RefundLineRequest,
  RefundQuote,
  RefundRequest,
  RefundResult,
  RefundTenderRequest,
  Sale,
  SaleLine,
  SalesApi,
  TillProduct,
  asMoney,
  asQuantity,
  compareDecimal,
  formatMoney,
  isDecimalString,
  refusalOf,
  ulid,
} from '@flexipos/shared';
import { firstValueFrom } from 'rxjs';
import { TillMessage, describeError } from '../errors/refusal-catalogue';
import { ApprovalAsk, ApprovalFlow, isStaleApproval, withApproval } from './approval-flow';
import { Connectivity } from './connectivity';
import { Notifier } from './notifier';

/** A refund of a sale the till loaded, or a goodwill refund of products with no original sale. */
export type RefundMode = 'referenced' | 'goodwill';

/** What the refund dialog is opened with. A void that sends the cashier here passes `saleId` (AC-57). */
export interface RefundOpenData {
  saleId?: string;
  mode?: RefundMode;
}

/** The statuses a sale can be looked up in for a refund; the server decides what is refundable. */
export const REFUNDABLE_LOOKUP = ['completed', 'partially_refunded', 'refunded'] as const;

/** Shown for a sale that is unknown or belongs to another branch (`not_found`, `not_refundable`). */
export const SALE_NOT_FOUND = 'Sale not found at this branch';

/** A refund payout above what is owed (`exceeds_balance`); no change is given on a refund (AC-64). */
export const OWED_BACK_EXCEEDED = 'More than is owed back';

/** The choice made on one line of the loaded sale. */
export interface LineEntry {
  selected: boolean;
  quantity: string;
}

/** One goodwill line: a product picked from the search, and the quantity typed for it. */
export interface GoodwillLine {
  key: number;
  product: TillProduct;
  quantity: string;
}

/** True when nothing of this line is left to refund, as the server counted it (AC-59). */
export function isFullyRefunded(line: SaleLine): boolean {
  return compareDecimal(line.refundable_quantity, '0') <= 0;
}

/**
 * What is wrong with a quantity typed for a refund, or null. Zero, negative, empty and more than
 * four decimals are refused in the form; so is more than `refundable` when the line has a limit.
 * Everything is a decimal-string comparison, never a float (D-6, AC-60).
 */
export function quantityProblem(text: string, refundable: string | null): string | null {
  if (text.trim() === '') {
    return 'Enter a quantity';
  }
  if (!isDecimalString(text, { maxScale: 99, allowNegative: true })) {
    return 'Enter the quantity as a number';
  }
  if (!isDecimalString(text, { maxScale: 99 })) {
    return 'The quantity must be more than zero';
  }
  if (!isDecimalString(text, { maxScale: 4, allowZero: false })) {
    return isDecimalString(text, { maxScale: 99, allowZero: false })
      ? 'At most 4 decimal places'
      : 'The quantity must be more than zero';
  }
  if (refundable !== null && compareDecimal(text, refundable) > 0) {
    return `More than is left to refund (at most ${refundable})`;
  }
  return null;
}

/** A failed refund call as words. `exceeds_balance` reads as a refund, not as a payment. */
export function describeRefundError(err: unknown): TillMessage {
  if (err instanceof ApiError && err.status > 0) {
    const code = refusalOf(err);
    if (code === 'exceeds_balance') {
      return { kind: 'refusal', text: OWED_BACK_EXCEEDED, code };
    }
    if (code === 'not_found' || code === 'not_refundable') {
      return { kind: 'refusal', text: SALE_NOT_FOUND, code };
    }
  }
  return describeError(err);
}

/** True when the call never got an answer (status 0): the refund may or may not be recorded. */
function answerLost(err: unknown): boolean {
  return err instanceof ApiError && err.status === 0;
}

/** The ids a submission of one exact refund sends, kept so a retry of it cannot refund twice. */
interface Attempt {
  key: string;
  refundId: string;
  tenderIds: string[];
}

/** What a submission is made of, once the form has been checked. */
interface Prepared {
  saleId: string | null;
  lines: RefundLineRequest[];
  tenders: RefundTenderRequest[];
  approval: ApprovalAsk;
}

/**
 * Referenced and goodwill refunds (T-037 AC-59 to AC-64). The till never prices a refund line:
 * the server's quote (`LINE_PRICER.quoteRefund`) is what is shown and what is paid out. The refund
 * `id` and the tender ids are made once per exact refund and re-sent unchanged after a lost
 * answer, so the server's idempotency (replay returns the stored refund) holds. The approval is
 * asked through `withApproval`; `refunds.create` consumes it once, so it is only kept in memory
 * for the retry of an answer that never came.
 */
@Injectable()
export class RefundStore {
  private readonly api = inject(SalesApi);
  private readonly pricer = inject(LINE_PRICER);
  private readonly connectivity = inject(Connectivity);
  private readonly flow = inject(ApprovalFlow);
  private readonly notifier = inject(Notifier);

  private attempt: Attempt | null = null;
  private heldApproval: string | null = null;
  private nextKey = 1;

  readonly mode = signal<RefundMode>('referenced');
  /** The original sale as the server last sent it (referenced mode). */
  readonly sale = signal<Sale | null>(null);
  readonly entries = signal<Record<string, LineEntry>>({});
  readonly goodwillLines = signal<GoodwillLine[]>([]);
  readonly quote = signal<RefundQuote | null>(null);
  readonly methods = signal<PaymentMethod[]>([]);
  readonly methodId = signal('');
  readonly amount = signal('');
  readonly reference = signal('');
  readonly result = signal<RefundResult | null>(null);
  /** The last thing that went wrong, in words. */
  readonly problem = signal('');
  /** Set once the cashier has tried to go on, so the form shows what is missing. */
  readonly showProblems = signal(false);
  readonly busy = signal(false);

  /** Refund methods only (`allows_refund`): the cashier is never offered another (AC-64). */
  readonly refundMethods = computed(() => this.methods().filter((m) => m.allows_refund));
  readonly method = computed(
    () => this.refundMethods().find((m) => m.id === this.methodId()) ?? null,
  );
  /** A refund of nothing needs no tender (AC-64). */
  readonly zeroTotal = computed(() => {
    const quote = this.quote();
    return quote !== null && compareDecimal(quote.total, '0') === 0;
  });

  /** What is wrong with each selected original line, by line id. */
  readonly lineProblems = computed(() => {
    const found: Record<string, string> = {};
    const entries = this.entries();
    for (const line of this.sale()?.lines ?? []) {
      const entry = entries[line.id];
      if (entry?.selected) {
        const problem = quantityProblem(entry.quantity, line.refundable_quantity);
        if (problem !== null) {
          found[line.id] = problem;
        }
      }
    }
    return found;
  });

  /** What is wrong with each goodwill line, by key. */
  readonly goodwillProblems = computed(() => {
    const found: Record<number, string> = {};
    for (const line of this.goodwillLines()) {
      const problem = quantityProblem(line.quantity, null);
      if (problem !== null) {
        found[line.key] = problem;
      }
    }
    return found;
  });

  /** Opens the dialog's session: a clean slate, the refund methods, and the sale named, if any. */
  async start(data: RefundOpenData | undefined): Promise<void> {
    this.reset();
    this.mode.set(data?.mode ?? 'referenced');
    await this.loadMethods();
    if (data?.saleId) {
      await this.loadSale(data.saleId);
    }
  }

  setMode(mode: RefundMode): void {
    if (mode !== this.mode()) {
      this.reset();
      this.mode.set(mode);
    }
  }

  /** Looks a sale up by its number among this branch's sales. */
  async findByNumber(typed: string): Promise<void> {
    const number = typed.trim();
    if (number === '') {
      this.problem.set('Enter a sale number');
      return;
    }
    this.busy.set(true);
    this.problem.set('');
    try {
      const page = await firstValueFrom(
        this.connectivity.track(
          this.api.listSales({
            number,
            scope: 'branch',
            type: 'sale',
            status: [...REFUNDABLE_LOOKUP],
            limit: 1,
          }),
        ),
      );
      const found = page.items[0];
      if (!found) {
        this.problem.set(SALE_NOT_FOUND);
        return;
      }
      await this.loadSale(found.id);
    } catch (err) {
      this.problem.set(describeRefundError(err).text);
    } finally {
      this.busy.set(false);
    }
  }

  /** Loads the original sale from the server and lists its lines (AC-59). */
  async loadSale(saleId: string): Promise<void> {
    this.problem.set('');
    try {
      const sale = await firstValueFrom(this.connectivity.track(this.api.getSale(saleId)));
      if (sale.type !== 'sale' || !REFUNDABLE_LOOKUP.some((s) => s === sale.status)) {
        this.problem.set(SALE_NOT_FOUND);
        return;
      }
      this.sale.set(sale);
      this.entries.set({});
      this.invalidate();
    } catch (err) {
      this.problem.set(describeRefundError(err).text);
    }
  }

  selectLine(line: SaleLine, selected: boolean): void {
    if (isFullyRefunded(line)) {
      return;
    }
    this.entries.update((all) => ({
      ...all,
      [line.id]: { selected, quantity: all[line.id]?.quantity ?? line.refundable_quantity },
    }));
    this.invalidate();
  }

  setQuantity(line: SaleLine, quantity: string): void {
    this.entries.update((all) => ({ ...all, [line.id]: { selected: true, quantity } }));
    this.invalidate();
  }

  addGoodwill(product: TillProduct): void {
    if (this.goodwillLines().some((l) => l.product.id === product.id)) {
      return;
    }
    const line: GoodwillLine = { key: this.nextKey, product, quantity: '1' };
    this.nextKey += 1;
    this.goodwillLines.update((all) => [...all, line]);
    this.invalidate();
  }

  setGoodwillQuantity(key: number, quantity: string): void {
    this.goodwillLines.update((all) => all.map((l) => (l.key === key ? { ...l, quantity } : l)));
    this.invalidate();
  }

  removeGoodwill(key: number): void {
    this.goodwillLines.update((all) => all.filter((l) => l.key !== key));
    this.invalidate();
  }

  setMethod(id: string): void {
    this.methodId.set(id);
  }

  setAmount(text: string): void {
    this.amount.set(text);
  }

  setReference(text: string): void {
    this.reference.set(text);
  }

  /** The lines to quote and to refund, or null with the reason shown. */
  private requestLines(): RefundLineRequest[] | null {
    this.showProblems.set(true);
    if (this.mode() === 'referenced') {
      const entries = this.entries();
      const chosen = (this.sale()?.lines ?? []).filter((l) => entries[l.id]?.selected);
      if (chosen.length === 0) {
        this.problem.set('Choose at least one line to refund');
        return null;
      }
      if (Object.keys(this.lineProblems()).length > 0) {
        this.problem.set('Fix the quantities marked below');
        return null;
      }
      return chosen.map((l) => ({
        refund_of_item_id: l.id,
        quantity: asQuantity(entries[l.id]?.quantity ?? l.refundable_quantity),
      }));
    }
    const lines = this.goodwillLines();
    if (lines.length === 0) {
      this.problem.set('Pick at least one product to refund');
      return null;
    }
    if (Object.keys(this.goodwillProblems()).length > 0) {
      this.problem.set('Fix the quantities marked below');
      return null;
    }
    return lines.map((l) => ({
      product_id: l.product.id,
      unit_id: l.product.unit_id,
      quantity: asQuantity(l.quantity),
    }));
  }

  /** Asks the server what the chosen lines refund to (P-7). Nothing is written. */
  async requestQuote(): Promise<void> {
    if (this.busy()) {
      return;
    }
    this.problem.set('');
    const lines = this.requestLines();
    if (lines === null) {
      return;
    }
    this.busy.set(true);
    try {
      const quote = await firstValueFrom(
        this.connectivity.track(
          this.pricer.quoteRefund({ reference_sale_id: this.sale()?.id ?? null, lines }),
        ),
      );
      this.quote.set(quote);
      this.amount.set(quote.total);
      this.reference.set('');
      if (this.method() === null) {
        this.methodId.set(this.refundMethods()[0]?.id ?? '');
      }
    } catch (err) {
      this.quote.set(null);
      this.problem.set(describeRefundError(err).text);
    } finally {
      this.busy.set(false);
    }
  }

  /** Checks the payout and the lines once more and builds what will be sent. */
  private prepare(): Prepared | null {
    const quote = this.quote();
    const lines = this.requestLines();
    if (quote === null || lines === null) {
      if (quote === null && lines !== null) {
        this.problem.set('Price the refund first');
      }
      return null;
    }
    const tenders: RefundTenderRequest[] = [];
    if (compareDecimal(quote.total, '0') !== 0) {
      const method = this.method();
      const amount = this.amount().trim();
      if (method === null) {
        this.problem.set('Choose how to pay the refund out');
        return null;
      }
      if (!isDecimalString(amount, { allowZero: false })) {
        this.problem.set('Enter the amount to pay out, as a positive number');
        return null;
      }
      const reference = this.reference().trim();
      if (method.requires_reference && reference === '') {
        this.problem.set(`${method.name} needs a reference`);
        return null;
      }
      tenders.push({
        id: '',
        payment_method_id: method.id,
        amount: asMoney(amount),
        reference: method.requires_reference ? reference : null,
      });
    }
    const saleId = this.sale()?.id ?? null;
    const key = JSON.stringify([
      saleId,
      lines,
      tenders.map((t) => [t.payment_method_id, t.amount, t.reference]),
    ]);
    if (this.attempt?.key !== key) {
      this.attempt = { key, refundId: ulid(), tenderIds: tenders.map(() => ulid()) };
    }
    const ids = this.attempt.tenderIds;
    tenders.forEach((t, i) => {
      t.id = ids[i] ?? t.id;
    });
    return { saleId, lines, tenders, approval: this.approvalFor(saleId, quote) };
  }

  /** The approval to ask: `refund` bound to the original sale, or goodwill with no sale (AC-61, AC-63). */
  private approvalFor(saleId: string | null, quote: RefundQuote): ApprovalAsk {
    const label = this.sale()?.number ?? this.sale()?.reference ?? null;
    return {
      request: {
        type: 'refund',
        reference_type: saleId === null ? 'goodwill_refund' : 'sale',
        reference_id: saleId,
        payload: null,
      },
      summary: {
        title: saleId === null ? 'Goodwill refund' : `Refund of sale ${label ?? 'this sale'}`,
        lines: [`Refund total ${formatMoney(quote.total)} ${quote.currency}`],
      },
    };
  }

  /** Sends the refund; after a lost answer, reads the original again and asks once more with the same ids. */
  private async send(request: RefundRequest): Promise<RefundResult> {
    try {
      return await firstValueFrom(this.connectivity.track(this.api.createRefund(request)));
    } catch (err) {
      if (!answerLost(err)) {
        throw err;
      }
      try {
        await this.reloadOriginal();
      } catch {
        // Still unreachable: the first error is the one to show; the next Submit re-sends the same ids.
        throw err;
      }
      return firstValueFrom(this.connectivity.track(this.api.createRefund(request)));
    }
  }

  private async reloadOriginal(): Promise<void> {
    const id = this.sale()?.id;
    if (id) {
      this.sale.set(await firstValueFrom(this.connectivity.track(this.api.getSale(id))));
    } else {
      // A goodwill refund has no original: any read proves the server is back.
      await firstValueFrom(this.connectivity.track(this.api.paymentMethods()));
    }
  }

  /** Asks for approval and submits the refund (AC-61 to AC-64). */
  async submit(): Promise<void> {
    if (this.busy()) {
      return;
    }
    this.problem.set('');
    const prepared = this.prepare();
    const attempt = this.attempt;
    if (prepared === null || attempt === null) {
      return;
    }
    const act = (approvalId: string): Promise<RefundResult> => {
      this.heldApproval = approvalId;
      return this.send({
        id: attempt.refundId,
        reference_sale_id: prepared.saleId,
        approval_id: approvalId,
        lines: prepared.lines,
        tenders: prepared.tenders,
      });
    };
    this.busy.set(true);
    try {
      let result: RefundResult | null = null;
      if (this.heldApproval !== null) {
        // The answer to an earlier try never came: the approval it used may be unspent.
        try {
          result = await act(this.heldApproval);
        } catch (err) {
          if (!isStaleApproval(err)) {
            throw err;
          }
          this.heldApproval = null;
        }
      }
      if (result === null) {
        const outcome = await withApproval(
          this.flow,
          () => prepared.approval,
          act,
          (err) => this.notifier.fail(err),
        );
        if (outcome.kind === 'rejected') {
          this.problem.set('Approval rejected: nothing was refunded');
          return;
        }
        if (outcome.kind === 'cancelled') {
          return;
        }
        result = outcome.value;
      }
      this.heldApproval = null;
      this.attempt = null;
      this.result.set(result);
    } catch (err) {
      this.problem.set(describeRefundError(err).text);
    } finally {
      this.busy.set(false);
    }
  }

  /** A change to what is being refunded makes the quote and the checks stale. */
  private invalidate(): void {
    this.quote.set(null);
    this.problem.set('');
  }

  private reset(): void {
    this.attempt = null;
    this.heldApproval = null;
    this.mode.set('referenced');
    this.sale.set(null);
    this.entries.set({});
    this.goodwillLines.set([]);
    this.quote.set(null);
    this.amount.set('');
    this.reference.set('');
    this.methodId.set('');
    this.result.set(null);
    this.problem.set('');
    this.showProblems.set(false);
    this.busy.set(false);
  }

  private async loadMethods(): Promise<void> {
    try {
      this.methods.set(await firstValueFrom(this.connectivity.track(this.api.paymentMethods())));
    } catch (err) {
      this.problem.set(describeRefundError(err).text);
    }
  }
}

import { ChangeDetectionStrategy, Component, ElementRef, inject } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from '@flexipos/shared';
import { ConfirmDialog } from './dialogs/confirm/confirm-dialog';
import { CustomerDialog } from './dialogs/customer/customer-dialog';
import { DiscountPickerDialog } from './dialogs/discount-picker/discount-picker-dialog';
import { FindSaleDialog } from './dialogs/find-sale/find-sale-dialog';
import { HelpDialog } from './dialogs/help/help-dialog';
import { LineEditDialog } from './dialogs/line-edit/line-edit-dialog';
import { PaymentDialog } from './dialogs/payment/payment-dialog';
import { PriceOverrideDialog } from './dialogs/price-override/price-override-dialog';
import { RecallDialog } from './dialogs/recall/recall-dialog';
import { RefundDialog } from './dialogs/refund/refund-dialog';
import { ShiftMenuDialog } from './dialogs/shift-menu/shift-menu-dialog';
import { SupervisorDialog } from './dialogs/supervisor/supervisor-dialog';
import { VoidDialog } from './dialogs/void/void-dialog';
import { WeightPromptDialog } from './dialogs/weight-prompt/weight-prompt-dialog';
import { KeymapContext, TillAction, matchKey, unavailableReason } from './keyboard/keymap';
import { Cart } from './panels/cart/cart';
import { CompletionPanel } from './panels/completion-panel/completion-panel';
import { ProductGrid } from './panels/product-grid/product-grid';
import { ProductSearch } from './panels/product-search/product-search';
import { ScanField } from './panels/scan-field/scan-field';
import { ShiftOpenPrompt } from './panels/shift-open-prompt/shift-open-prompt';
import { DialogHost } from './state/dialog-host';
import { Notifier } from './state/notifier';
import { SaleStore } from './state/sale-store';
import { ShiftStore } from './state/shift-store';
import { TillContext } from './state/till-context';
import { provideTill } from './till.providers';
import { MessageBar } from './ui/message-bar/message-bar';
import { NetworkBanner } from './ui/network-banner/network-banner';

/** Input types a cashier types text into: some keys of the key map yield to them (`TypingPolicy`). */
const TEXT_INPUT_TYPES = ['text', 'search', 'password', 'number', 'tel', 'email', 'url'];

function isTypingTarget(target: EventTarget | null): target is HTMLElement {
  if (!(target instanceof HTMLElement)) {
    return false;
  }
  if (target instanceof HTMLInputElement) {
    return TEXT_INPUT_TYPES.includes(target.type);
  }
  return (
    target instanceof HTMLTextAreaElement ||
    target instanceof HTMLSelectElement ||
    target.isContentEditable
  );
}

function isEmptyField(target: HTMLElement): boolean {
  if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) {
    return target.value === '';
  }
  return false;
}

/**
 * The sale screen (T-037): header, network banner, scan field, product search and grid, cart,
 * message bar and the one dialog outlet. Every store is provided here (`provideTill`), so nothing
 * outlives the page. One `(keydown)` listener maps keys through the key map to actions, and says
 * why when an action is not available (AC-80).
 */
@Component({
  selector: 'pos-till-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    Cart,
    CompletionPanel,
    ConfirmDialog,
    CustomerDialog,
    DiscountPickerDialog,
    FindSaleDialog,
    HelpDialog,
    LineEditDialog,
    MessageBar,
    NetworkBanner,
    PaymentDialog,
    PriceOverrideDialog,
    ProductGrid,
    ProductSearch,
    RecallDialog,
    RefundDialog,
    ScanField,
    ShiftMenuDialog,
    ShiftOpenPrompt,
    SupervisorDialog,
    VoidDialog,
    WeightPromptDialog,
  ],
  providers: [provideTill()],
  host: { '(keydown)': 'onKeydown($event)' },
  templateUrl: './till-page.html',
  styleUrl: './till-page.scss',
})
export class TillPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly element = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly notifier = inject(Notifier);
  protected readonly context = inject(TillContext);
  protected readonly shift = inject(ShiftStore);
  protected readonly sale = inject(SaleStore);
  protected readonly dialogs = inject(DialogHost);

  constructor() {
    void this.shift.load();
  }

  protected switchBranch(): void {
    this.dialogs.closeAll();
    this.auth.clearContext();
    void this.router.navigate(['/context']);
  }

  protected signOut(): void {
    this.dialogs.closeAll();
    this.auth.logout();
    void this.router.navigate(['/login']);
  }

  /** The one key handler of the page: key map in, action out, a reason when it cannot run. */
  protected onKeydown(event: KeyboardEvent): void {
    if (event.defaultPrevented) {
      return;
    }
    const match = matchKey(event);
    if (!match || match.entry.scope === 'control') {
      return;
    }
    // Text and list keys belong to the field or the dialog that has the focus.
    if (match.binding.typing === 'when-empty') {
      const typing = isTypingTarget(event.target) && !isEmptyField(event.target);
      if (this.dialogs.isOpen() || typing) {
        return;
      }
    }
    event.preventDefault();
    const reason = unavailableReason(match.entry, this.keymapContext());
    if (reason !== null) {
      this.notifier.info(reason);
      return;
    }
    this.run(match.entry.action);
  }

  private keymapContext(): KeymapContext {
    return {
      shiftOpen: this.shift.shiftOpen(),
      lineCount: this.sale.lineCount(),
      hasSelectedLine: this.sale.selectedLineId() !== null,
      saleStatus: this.sale.status(),
      dialogOpen: this.dialogs.isOpen(),
    };
  }

  private run(action: TillAction): void {
    switch (action) {
      case 'pay':
        void this.dialogs.open('payment');
        break;
      case 'hold':
        void this.sale.hold();
        break;
      case 'recall':
        void this.dialogs.open('recall');
        break;
      case 'customer':
        void this.dialogs.open('customer');
        break;
      case 'refund':
        void this.dialogs.open('refund');
        break;
      case 'void':
        void this.dialogs.open('void');
        break;
      case 'shift-menu':
        void this.dialogs.open('shift-menu');
        break;
      case 'help':
        void this.dialogs.open('help');
        break;
      case 'search':
        this.element.nativeElement.querySelector<HTMLElement>('[data-till-search-field]')?.focus();
        break;
      case 'remove-line':
        void this.sale.removeSelectedLine();
        break;
      case 'select-previous':
        this.sale.selectPrevious();
        break;
      case 'select-next':
        this.sale.selectNext();
        break;
      case 'cancel':
        if (this.dialogs.isOpen()) {
          this.dialogs.close();
        } else if (this.notifier.current() !== null) {
          this.notifier.dismiss();
        } else {
          this.dialogs.focusScanField();
        }
        break;
      case 'confirm':
        // Enter belongs to the control that has the focus; the map never routes it here.
        break;
    }
  }
}

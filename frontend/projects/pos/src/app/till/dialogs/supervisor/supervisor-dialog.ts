import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core';
import { SalesApi } from '@flexipos/shared';
import { firstValueFrom } from 'rxjs';
import { describeError } from '../../errors/refusal-catalogue';
import { Connectivity } from '../../state/connectivity';
import { DialogHost } from '../../state/dialog-host';
import { Notifier } from '../../state/notifier';
import {
  ApprovalSummary,
  SupervisorDialogData,
  SupervisorDialogResult,
} from '../../state/approval-flow';
import { DialogFrame } from '../../ui/dialog-frame';

/** The PIN is cleared when nobody has touched the dialog for this long (AC-66). */
export const SUPERVISOR_IDLE_MS = 60000;

const NO_SUMMARY: ApprovalSummary = { title: 'A supervisor must approve this' };

/**
 * The supervisor's code and PIN (T-037 AC-65 to AC-71). The cashier hands the till over: the
 * dialog shows what is being approved, takes an employee code and a PIN, and asks the server to
 * decide the approval it was opened for.
 *
 * The PIN is read from the password field at the moment of submit and nowhere else: it is not
 * a signal, a model or a field of this class, it is cleared in the same turn it is read (before
 * the call is made), and again on cancel, on close, on a refusal and after
 * {@link SUPERVISOR_IDLE_MS} of inactivity. The dialog closes with `'approved'` or `'rejected'`;
 * `Esc` and Cancel close it with nothing, and consume nothing (the approval stays pending).
 */
@Component({
  selector: 'pos-supervisor-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame],
  template: `
    <pos-dialog-frame heading="Supervisor approval">
      <div class="summary" data-testid="approval-summary">
        <p class="title">{{ summary().title }}</p>
        @if (summary().lines; as lines) {
          <ul>
            @for (line of lines; track $index) {
              <li>{{ line }}</li>
            }
          </ul>
        }
      </div>
      <form
        (submit)="onSubmit($event)"
        (input)="touch()"
        (keydown)="touch()"
        autocomplete="off"
        novalidate
      >
        <label>
          Employee code
          <input #code name="employee_code" type="text" autocomplete="off" data-autofocus />
        </label>
        <label>
          PIN
          <input #pin name="pin" type="password" autocomplete="off" inputmode="numeric" />
        </label>
        <p class="message" role="alert" data-testid="supervisor-message">{{ message() }}</p>
        <div class="actions">
          <button type="submit" class="primary" [disabled]="busy() || !online()">Approve</button>
          <button type="button" [disabled]="busy() || !online()" (click)="submit('reject')">
            Reject
          </button>
          <button type="button" (click)="cancel()">Cancel</button>
        </div>
      </form>
    </pos-dialog-frame>
  `,
  styles: `
    .summary .title {
      margin: 0;
      font-weight: 600;
    }

    ul {
      margin: 4px 0 0;
      padding-left: 20px;
    }

    form {
      display: grid;
      gap: 10px;
    }

    label {
      display: grid;
      gap: 4px;
    }

    input {
      min-height: 44px;
      padding: 0 10px;
      font: inherit;
    }

    .message {
      min-height: 1.4em;
      margin: 0;
      color: var(--fp-danger, #b00020);
    }

    .actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    button {
      min-height: 44px;
      padding: 0 16px;
    }
  `,
})
export class SupervisorDialog {
  private readonly api = inject(SalesApi);
  private readonly connectivity = inject(Connectivity);
  protected readonly online = this.connectivity.online;
  private readonly notifier = inject(Notifier);
  protected readonly dialogs = inject(DialogHost);
  private readonly codeField = viewChild<ElementRef<HTMLInputElement>>('code');
  private readonly pinField = viewChild<ElementRef<HTMLInputElement>>('pin');
  private idleTimer: ReturnType<typeof setTimeout> | null = null;
  /** True once the dialog is gone (cancelled or closed): a late answer must not touch the screen. */
  private gone = false;

  /** What the opener passed to `DialogHost.open`: the approval and what it is for. */
  readonly data = input<unknown>();
  protected readonly message = signal('');
  protected readonly busy = signal(false);

  constructor() {
    this.armIdle();
    inject(DestroyRef).onDestroy(() => {
      this.gone = true;
      this.stopIdle();
      this.clearPin();
    });
  }

  protected summary(): ApprovalSummary {
    return this.asData()?.summary ?? NO_SUMMARY;
  }

  /** Any typing or key press counts as activity and restarts the idle clock. */
  protected touch(): void {
    this.armIdle();
  }

  protected onSubmit(event: Event): void {
    event.preventDefault();
    void this.submit('approve');
  }

  protected cancel(): void {
    this.clearPin();
    this.dialogs.close();
  }

  protected async submit(decision: 'approve' | 'reject'): Promise<void> {
    const data = this.asData();
    if (!data || this.busy()) {
      return;
    }
    const code = this.codeField()?.nativeElement.value.trim() ?? '';
    // Read once, and cleared in the same turn: before the call, so no await ever holds it in a field.
    const pin = this.pinField()?.nativeElement.value ?? '';
    this.clearPin();
    if (code === '' || pin === '') {
      this.message.set('Enter the supervisor code and PIN');
      this.focus(code === '' ? this.codeField() : this.pinField());
      return;
    }
    this.busy.set(true);
    this.message.set('');
    this.stopIdle();
    const mine = this.dialogs.currentId();
    try {
      await firstValueFrom(
        this.connectivity.track(
          this.api.decideApproval(data.approvalId, {
            decision,
            method: 'pin_local',
            employee_code: code,
            pin,
          }),
        ),
      );
      const result: SupervisorDialogResult = decision === 'approve' ? 'approved' : 'rejected';
      this.dialogs.closeIf(mine, result);
    } catch (err) {
      this.onRefused(err, mine);
    } finally {
      this.busy.set(false);
      if (!this.gone) {
        this.armIdle();
      }
    }
  }

  private onRefused(err: unknown, mine: number | undefined): void {
    const described = describeError(err);
    switch (described.code) {
      case 'self_approval_not_allowed':
      case 'not_authorised':
        // Hand over: the next supervisor types their own code and PIN, without leaving.
        this.message.set(`${described.text}. Hand over to another supervisor.`);
        this.setValue(this.codeField(), '');
        this.focus(this.codeField());
        return;
      case 'approval_not_pending':
      case 'approval_already_used':
      case 'approval_not_approved':
      case 'approval_mismatch':
        // This approval can no longer be decided; the action will ask for a new one.
        this.notifier.fail(err);
        this.dialogs.closeIf(mine);
        return;
      default:
        this.message.set(described.text);
        this.focus(described.code === 'pin_invalid' ? this.pinField() : this.codeField());
    }
  }

  private asData(): SupervisorDialogData | null {
    const value = this.data();
    if (typeof value !== 'object' || value === null) {
      return null;
    }
    const data = value as Partial<SupervisorDialogData>;
    return typeof data.approvalId === 'string' && data.summary
      ? { approvalId: data.approvalId, summary: data.summary }
      : null;
  }

  private clearPin(): void {
    this.setValue(this.pinField(), '');
  }

  private setValue(field: ElementRef<HTMLInputElement> | undefined, value: string): void {
    if (field) {
      field.nativeElement.value = value;
    }
  }

  private focus(field: ElementRef<HTMLInputElement> | undefined): void {
    field?.nativeElement.focus();
  }

  private armIdle(): void {
    this.stopIdle();
    this.idleTimer = setTimeout(() => {
      this.idleTimer = null;
      this.clearPin();
      this.message.set('The PIN was cleared after 60 seconds without activity');
    }, SUPERVISOR_IDLE_MS);
  }

  private stopIdle(): void {
    if (this.idleTimer !== null) {
      clearTimeout(this.idleTimer);
      this.idleTimer = null;
    }
  }
}

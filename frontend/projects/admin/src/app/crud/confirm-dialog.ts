import {
  AfterViewInit,
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  OnDestroy,
  input,
  output,
  viewChild,
} from '@angular/core';

let nextDialogId = 0;

/**
 * The delete confirmation (T-036 AC-19). Create it only while it is open (`@if`): on open it
 * remembers the focused element and focuses Cancel, Tab cycles inside, Escape cancels, and
 * on close focus returns to the element that opened it.
 */
@Component({
  selector: 'adm-confirm-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'adm-dialog-backdrop' },
  template: `
    <div
      #dialog
      class="adm-dialog"
      role="dialog"
      aria-modal="true"
      [attr.aria-labelledby]="titleId"
      (keydown)="onKeydown($event)"
    >
      <h2 [id]="titleId">{{ title() }}</h2>
      @if (detail(); as text) {
        <p class="fp-muted">{{ text }}</p>
      }
      <div class="adm-dialog-actions">
        <button #cancelButton type="button" (click)="cancelled.emit()">Cancel</button>
        <button type="button" class="fp-primary" [disabled]="busy()" (click)="confirmed.emit()">
          {{ confirmLabel() }}
        </button>
      </div>
    </div>
  `,
})
export class ConfirmDialog implements AfterViewInit, OnDestroy {
  /** The row's name, shown in the question: "Delete <name>?". */
  readonly name = input.required<string>();
  readonly verb = input('Delete');
  readonly confirmLabel = input('Delete');
  readonly detail = input<string | null>(null);
  readonly busy = input(false);
  readonly confirmed = output<void>();
  readonly cancelled = output<void>();

  protected readonly titleId = `adm-dialog-title-${nextDialogId++}`;
  private readonly dialog = viewChild.required<ElementRef<HTMLElement>>('dialog');
  private readonly cancelButton = viewChild.required<ElementRef<HTMLButtonElement>>('cancelButton');
  private trigger: HTMLElement | null = document.activeElement as HTMLElement | null;

  protected title(): string {
    return `${this.verb()} ${this.name()}?`;
  }

  ngAfterViewInit(): void {
    this.cancelButton().nativeElement.focus();
  }

  ngOnDestroy(): void {
    const trigger = this.trigger;
    this.trigger = null;
    if (trigger && trigger.isConnected) {
      trigger.focus();
    }
  }

  protected onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      event.preventDefault();
      this.cancelled.emit();
      return;
    }
    if (event.key !== 'Tab') {
      return;
    }
    const focusable = Array.from(
      this.dialog().nativeElement.querySelectorAll<HTMLElement>('button:not([disabled])'),
    );
    if (focusable.length === 0) {
      return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;
    if (event.shiftKey && active === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }
}

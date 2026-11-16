import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  afterNextRender,
  inject,
  input,
} from '@angular/core';
import { DialogHost } from '../state/dialog-host';

const FOCUSABLE = [
  'button:not([disabled])',
  '[href]',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

let nextTitleId = 0;

/**
 * The frame every dialog wears (T-037 AC-82): `role="dialog"`, `aria-modal`, a labelled heading,
 * the first control focused when it opens (or the one marked `data-autofocus`), Tab and Shift+Tab
 * wrapped inside it, and `Esc` closing it. Closing returns the focus to the control that opened
 * it, or to the scan field (`DialogHost`).
 */
@Component({
  selector: 'pos-dialog-frame',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="backdrop">
      <div
        class="frame"
        role="dialog"
        aria-modal="true"
        tabindex="-1"
        [attr.aria-labelledby]="titleId"
        (keydown)="onKeydown($event)"
      >
        <h2 class="title" [id]="titleId">{{ heading() }}</h2>
        <ng-content />
      </div>
    </div>
  `,
  styles: `
    .backdrop {
      position: fixed;
      inset: 0;
      z-index: 10;
      display: grid;
      place-items: center;
      padding: 16px;
      background: rgb(0 0 0 / 45%);
    }

    .frame {
      width: min(100%, 560px);
      max-height: 100%;
      overflow: auto;
      display: flex;
      flex-direction: column;
      gap: 12px;
      padding: 20px;
      background: var(--fp-surface);
      color: var(--fp-text);
      border: 1px solid var(--fp-border);
      border-radius: var(--fp-radius);
    }

    .title {
      margin: 0;
      font-size: 1.2rem;
    }
  `,
})
export class DialogFrame {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly dialogs = inject(DialogHost);

  readonly heading = input.required<string>();
  protected readonly titleId = `pos-dialog-title-${(nextTitleId += 1)}`;

  constructor() {
    afterNextRender(() => this.focusFirst());
  }

  protected onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      this.dialogs.close();
      return;
    }
    if (event.key === 'Tab') {
      this.trapTab(event);
    }
  }

  private frame(): HTMLElement {
    return this.host.nativeElement.querySelector<HTMLElement>('.frame') ?? this.host.nativeElement;
  }

  private focusables(): HTMLElement[] {
    return Array.from(this.frame().querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
      (el) => !el.hidden && el.getAttribute('aria-hidden') !== 'true',
    );
  }

  private focusFirst(): void {
    const preferred = this.frame().querySelector<HTMLElement>('[data-autofocus]');
    (preferred ?? this.focusables()[0] ?? this.frame()).focus();
  }

  private trapTab(event: KeyboardEvent): void {
    const items = this.focusables();
    const first = items[0];
    const last = items[items.length - 1];
    if (!first || !last) {
      event.preventDefault();
      this.frame().focus();
      return;
    }
    const active = this.host.nativeElement.ownerDocument.activeElement;
    const outside = !this.frame().contains(active) || active === this.frame();
    if (event.shiftKey && (active === first || outside)) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (active === last || outside)) {
      event.preventDefault();
      first.focus();
    }
  }
}

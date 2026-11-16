import { ChangeDetectionStrategy, Component, ElementRef, inject, viewChild } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { DialogHost } from '../state/dialog-host';
import { DialogFrame } from './dialog-frame';

@Component({
  selector: 'pos-frame-host',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame],
  providers: [DialogHost],
  template: `
    <button type="button" #opener (click)="show(opener)">Open</button>
    <input data-till-scan-field />
    @if (dialogs.current(); as dialog) {
      <pos-dialog-frame heading="Test dialog">
        @if (dialog.kind === 'help') {
          <input id="first" />
          <button id="middle" type="button">Middle</button>
          <button id="last" type="button" (click)="dialogs.close('done')">Last</button>
        } @else {
          <p>Nothing to focus</p>
        }
      </pos-dialog-frame>
    }
  `,
})
class FrameHost {
  protected readonly dialogs = inject(DialogHost);
  readonly openerButton = viewChild.required<ElementRef<HTMLButtonElement>>('opener');
  result: unknown = 'unset';

  protected show(opener: HTMLElement): void {
    void this.dialogs.open('help', undefined, opener).then((r) => (this.result = r));
  }

  showBare(): void {
    void this.dialogs.open('confirm');
  }
}

describe('DialogFrame (AC-82)', () => {
  async function open(): Promise<{
    root: HTMLElement;
    host: FrameHost;
    fixture: ComponentFixture<FrameHost>;
  }> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ imports: [FrameHost] });
    const fixture = TestBed.createComponent(FrameHost);
    fixture.detectChanges();
    const host = fixture.componentInstance;
    const root = fixture.nativeElement as HTMLElement;
    host.openerButton().nativeElement.focus();
    host.openerButton().nativeElement.click();
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    return { root, host, fixture };
  }

  function press(root: HTMLElement, key: string, shift = false): KeyboardEvent {
    const target = document.activeElement ?? root;
    const event = new KeyboardEvent('keydown', {
      key,
      shiftKey: shift,
      bubbles: true,
      cancelable: true,
    });
    target.dispatchEvent(event);
    return event;
  }

  it('is a labelled, modal dialog', async () => {
    const { root } = await open();
    const dialog = root.querySelector('[role="dialog"]')!;
    expect(dialog.getAttribute('aria-modal')).toBe('true');
    const title = root.querySelector(`#${dialog.getAttribute('aria-labelledby')}`);
    expect(title?.textContent?.trim()).toBe('Test dialog');
  });

  it('focuses the first control when it opens', async () => {
    const { root } = await open();
    expect(document.activeElement).toBe(root.querySelector('#first'));
  });

  it('wraps Tab from the last control to the first', async () => {
    const { root } = await open();
    root.querySelector<HTMLElement>('#last')!.focus();
    expect(press(root, 'Tab').defaultPrevented).toBe(true);
    expect(document.activeElement).toBe(root.querySelector('#first'));
  });

  it('wraps Shift+Tab from the first control to the last', async () => {
    const { root } = await open();
    expect(document.activeElement).toBe(root.querySelector('#first'));
    expect(press(root, 'Tab', true).defaultPrevented).toBe(true);
    expect(document.activeElement).toBe(root.querySelector('#last'));
  });

  it('lets Tab move on inside the dialog', async () => {
    const { root } = await open();
    expect(press(root, 'Tab').defaultPrevented).toBe(false);
  });

  it('closes on Esc and gives the focus back to the control that opened it', async () => {
    const { root, host, fixture } = await open();
    expect(press(root, 'Escape').defaultPrevented).toBe(true);
    fixture.detectChanges();
    expect(root.querySelector('[role="dialog"]')).toBeNull();
    expect(document.activeElement).toBe(host.openerButton().nativeElement);
    await Promise.resolve();
    expect(host.result).toBeUndefined();
  });

  it('gives the dialog its result when a control closes it', async () => {
    const { root, host } = await open();
    root.querySelector<HTMLElement>('#last')!.click();
    await Promise.resolve();
    expect(host.result).toBe('done');
  });

  it('returns the focus to the scan field when nothing opened it', async () => {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ imports: [FrameHost] });
    const fixture = TestBed.createComponent(FrameHost);
    fixture.detectChanges();
    document.body.focus();
    fixture.componentInstance.showBare();
    fixture.detectChanges();
    await fixture.whenStable();
    const root = fixture.nativeElement as HTMLElement;
    expect(root.querySelector('[role="dialog"]')).not.toBeNull();
    press(root, 'Escape');
    expect(document.activeElement).toBe(root.querySelector('[data-till-scan-field]'));
  });

  it('holds the focus on the frame itself when it has no control', async () => {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ imports: [FrameHost] });
    const fixture = TestBed.createComponent(FrameHost);
    fixture.detectChanges();
    fixture.componentInstance.showBare();
    fixture.detectChanges();
    await fixture.whenStable();
    const root = fixture.nativeElement as HTMLElement;
    expect(document.activeElement).toBe(root.querySelector('[role="dialog"]'));
    expect(press(root, 'Tab').defaultPrevented).toBe(true);
  });
});

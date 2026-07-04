import QRCode from 'qrcode';

const root = document.querySelector('[data-invitation-url]');
const canvas = document.querySelector('#qr');

if (root && canvas) {
    QRCode.toCanvas(canvas, root.dataset.invitationUrl, {
        width: 300,
        margin: 2,
        color: { dark: '#29251f', light: '#fffdf8' },
        errorCorrectionLevel: 'M',
    });
}

document.querySelector('[data-print]')?.addEventListener('click', () => window.print());

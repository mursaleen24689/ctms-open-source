import React from 'react';

const attribution = (
  <>
    <strong>Prepared and maintained by the CTMS team</strong>
    <span>Materials Testing Laboratories • Department of Materials Engineering</span>
    <span>Copyright © Example Technical University, 2026</span>
  </>
);

export default function InstitutionFooter({ style = {}, className = '' }) {
  return (
    <footer className={className} aria-label="CTMS attribution" style={{
      display: 'grid', gap: 2, textAlign: 'center', fontFamily: 'inherit',
      fontSize: 10, lineHeight: 1.4, letterSpacing: '0.15px', ...style,
    }}>
      {attribution}
    </footer>
  );
}

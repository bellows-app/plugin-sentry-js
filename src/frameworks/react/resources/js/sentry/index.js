import React from 'react';
import * as Sentry from '@sentry/react';

export const initSentry = import.meta.env.VITE_SENTRY_JS_DSN
    ? () =>
          Sentry.init({
              dsn: import.meta.env.VITE_SENTRY_JS_DSN,
              integrations: [
                  new Sentry.BrowserTracing({
                      // Set `tracePropagationTargets` to control for which URLs distributed tracing should be enabled
                      tracePropagationTargets: [
                          'localhost',
                          /^https:\/\/yourserver\.io\/api/,
                      ],
                      // See docs for support of different versions of variation of react router
                      // https://docs.sentry.io/platforms/javascript/guides/react/configuration/integrations/react-router/
                      routingInstrumentation:
                          Sentry.reactRouterV6Instrumentation(
                              React.useEffect,
                              useLocation,
                              useNavigationType,
                              createRoutesFromChildren,
                              matchRoutes,
                          ),
                  }),
                  new Sentry.Replay(),
              ],

              // Set tracesSampleRate to 1.0 to capture 100%
              // of transactions for performance monitoring.
              tracesSampleRate:
                  import.meta.env.VITE_SENTRY_JS_TRACES_SAMPLE_RATE || 1.0,

              // Capture Replay for 10% of all sessions,
              // plus for 100% of sessions with an error
              replaysSessionSampleRate: 0.1,
              replaysOnErrorSampleRate: 1.0,
          })
    : () => {};

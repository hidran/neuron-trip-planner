import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { createBrowserRouter, RouterProvider } from 'react-router';
import Layout from './components/Layout';
import Home from './pages/Home';
import TripPage from './pages/TripPage';

const router = createBrowserRouter([
    {
        element: <Layout />,
        children: [
            { path: '/', element: <Home /> },
            { path: '/trips/:id', element: <TripPage /> },
        ],
    },
]);

createRoot(document.getElementById('app')!).render(
    <StrictMode>
        <RouterProvider router={router} />
    </StrictMode>,
);
